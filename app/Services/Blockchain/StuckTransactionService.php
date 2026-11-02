<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasTopup;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\SpeedsUpTransactions;
use App\Support\Network;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class StuckTransactionService
{
    private const STUCK_MINUTES = 10;

    private const MAX_REPLACEMENTS = 5;

    public function __construct(private readonly BlockchainBroadcaster $broadcaster) {}

    public function run(): void
    {
        if (! $this->broadcaster instanceof SpeedsUpTransactions) {
            return;
        }

        $this->sweepCandidates()->each(function (TreasurySweep $sweep): void {
            $address = $sweep->deposit_address_id !== null
                ? DepositAddress::find($sweep->deposit_address_id)
                : Deposit::with('depositAddress')->find($sweep->deposit_id)?->depositAddress;

            if ($address === null) {
                return;
            }

            $this->handle($sweep, $sweep->network, (int) $address->derivation_index, $sweep->last_broadcast_at ?? $sweep->updated_at);
        });

        $this->withdrawalCandidates()->each(function (Withdrawal $withdrawal): void {
            $index = $this->treasuryIndex($withdrawal->network);

            if ($index !== null) {
                $this->handle($withdrawal, $withdrawal->network, $index, $withdrawal->last_broadcast_at ?? $withdrawal->sent_at);
            }
        });

        $this->topupCandidates()->each(function (GasTopup $topup): void {
            $index = $topup->source_index ?? $this->treasuryIndex($topup->network);

            if ($index !== null) {
                $this->handle($topup, $topup->network, $index, $topup->last_broadcast_at ?? $topup->broadcasted_at);
            }
        });

        $this->payoutCandidates()->each(function (TreasuryPayout $payout): void {
            $index = $this->treasuryIndex($payout->network);

            if ($index !== null) {
                $this->handle($payout, $payout->network, $index, $payout->last_broadcast_at ?? $payout->sent_at);
            }
        });
    }

    private function handle(Model $row, string $network, int $sourceIndex, ?Carbon $broadcastAt): void
    {
        $replaced = $row->replaced_tx_hashes ?? [];
        $receipt = null;

        // A replacement can make it into a block while the later hash dropped
        // out of the mempool — adopt whichever hash actually landed.
        if ($replaced !== []) {
            $receipt = $this->broadcaster->getTransactionReceipt($network, (string) $row->tx_hash);

            if (($receipt['status'] ?? null) === 'pending' && ($receipt['seen'] ?? true) === false) {
                foreach ($replaced as $hash) {
                    $old = $this->broadcaster->getTransactionReceipt($network, $hash);

                    if (in_array($old['status'] ?? null, ['confirmed', 'failed'], true)) {
                        $row->update([
                            'tx_hash' => $hash,
                            'replaced_tx_hashes' => [...array_values(array_diff($replaced, [$hash])), $row->tx_hash],
                        ]);
                        Log::warning('tx.replaced_hash_mined', [
                            'model' => $row->getTable(), 'id' => $row->getKey(),
                            'network' => $network, 'adopted' => $hash, 'dropped' => $row->tx_hash,
                        ]);

                        return;
                    }
                }
            }
        }

        if ($broadcastAt === null || $broadcastAt->gt(now()->subMinutes(self::STUCK_MINUTES))) {
            return;
        }

        $receipt ??= $this->broadcaster->getTransactionReceipt($network, (string) $row->tx_hash);

        // Confirmed/failed/unavailable receipts are the normal pollers' job.
        if ($receipt === null || ($receipt['status'] ?? 'pending') !== 'pending') {
            return;
        }

        if (count($replaced) >= self::MAX_REPLACEMENTS) {
            Log::critical('tx.speedup_exhausted', [
                'model' => $row->getTable(), 'id' => $row->getKey(),
                'network' => $network, 'tx_hash' => $row->tx_hash,
            ]);

            return;
        }

        $result = $this->broadcaster->speedUp($network, $sourceIndex, (string) $row->tx_hash);

        if ($result === null) {
            Log::info('tx.speedup_failed', [
                'model' => $row->getTable(), 'id' => $row->getKey(), 'network' => $network, 'tx_hash' => $row->tx_hash,
            ]);

            return;
        }

        if (($result['status'] ?? null) === 'replaced') {
            $oldHash = (string) $row->tx_hash;
            $row->update([
                'tx_hash' => $result['tx_hash'],
                'replaced_tx_hashes' => [...$replaced, $oldHash],
                'last_broadcast_at' => now(),
            ]);
            Log::warning('tx.sped_up', [
                'model' => $row->getTable(), 'id' => $row->getKey(), 'network' => $network,
                'old_tx_hash' => $oldHash,
                'new_tx_hash' => $result['tx_hash'],
                'gas_price_gwei' => $result['gas_price_gwei'] ?? null,
            ]);

            return;
        }

        Log::info('tx.speedup_result', [
            'model' => $row->getTable(), 'id' => $row->getKey(), 'network' => $network,
            'tx_hash' => $row->tx_hash, 'result' => $result,
        ]);
    }

    private function treasuryIndex(string $network): ?int
    {
        $index = TreasuryWallet::query()->where('network', $network)->value('derivation_index');

        return $index === null ? null : (int) $index;
    }

    private function sweepCandidates(): Collection
    {
        return TreasurySweep::query()
            ->where('status', 'pending')
            ->whereNotNull('tx_hash')
            ->get()
            ->filter(fn (TreasurySweep $sweep) => Network::family($sweep->network) === 'evm');
    }

    private function withdrawalCandidates(): Collection
    {
        return Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->where('status', 'sent')
            ->whereNotNull('tx_hash')
            ->where('sent_at', '>=', now()->subDays(3))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('gas_expenses')
                    ->whereColumn('gas_expenses.expensable_id', 'withdrawals.id')
                    ->where('gas_expenses.expensable_type', Withdrawal::class);
            })
            ->get()
            ->filter(fn (Withdrawal $withdrawal) => Network::family($withdrawal->network) === 'evm');
    }

    private function topupCandidates(): Collection
    {
        return GasTopup::query()
            ->where('status', 'broadcast')
            ->whereNotNull('tx_hash')
            ->get()
            ->filter(fn (GasTopup $topup) => Network::family($topup->network) === 'evm');
    }

    private function payoutCandidates(): Collection
    {
        return TreasuryPayout::query()
            ->where('status', 'sent')
            ->whereNotNull('tx_hash')
            ->get()
            ->filter(fn (TreasuryPayout $payout) => Network::family($payout->network) === 'evm');
    }
}
