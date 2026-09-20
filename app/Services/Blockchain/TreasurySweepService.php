<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Balance;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasExpense;
use App\Models\GasTopup;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\ReportsLastError;
use App\Support\Network;
use Illuminate\Support\Facades\DB;

class TreasurySweepService
{
    public function __construct(
        private readonly BlockchainBroadcaster $broadcaster,
        private ?GasTreasuryService $gasTreasury = null,
        private ?FeeConverter $feeConverter = null,
        private ?ConsolidationBiller $biller = null,
    ) {
        $this->gasTreasury ??= new GasTreasuryService($this->broadcaster);
        $this->feeConverter ??= new FeeConverter;
        $this->biller ??= new ConsolidationBiller($this->feeConverter);
    }

    public function sweep(): void
    {
        $this->billConsolidationCosts();

        $settings = PlatformSettings::instance();
        $valuations = UsdValuation::query()->pluck('conversion_value', 'network');

        $groups = Deposit::query()
            ->withoutGlobalScope('owner')
            ->join('deposit_addresses', 'deposit_addresses.id', '=', 'deposits.deposit_address_id')
            ->join('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
            ->where('deposits.status', 'credited')
            ->whereNull('deposits.swept_at')
            ->groupBy('deposits.deposit_address_id', 'deposit_addresses.network', 'customers.user_id')
            ->selectRaw('deposits.deposit_address_id, deposit_addresses.network, customers.user_id, SUM(deposits.gross_amount) as amount, MIN(deposits.credited_at) as oldest_credited_at')
            ->get();

        foreach ($groups as $group) {
            DB::transaction(function () use ($group, $settings, $valuations): void {
                $wallet = TreasuryWallet::query()
                    ->where('network', $group->network)
                    ->lockForUpdate()
                    ->first();

                if ($wallet === null) {
                    return;
                }

                $depositIds = Deposit::query()
                    ->withoutGlobalScope('owner')
                    ->where('deposit_address_id', $group->deposit_address_id)
                    ->where('status', 'credited')
                    ->whereNull('swept_at')
                    ->pluck('id');

                $sweep = TreasurySweep::query()
                    ->whereIn('status', ['pending', 'broadcast'])
                    ->where(function ($query) use ($group, $depositIds): void {
                        $query->where('deposit_address_id', $group->deposit_address_id)
                            ->orWhereIn('deposit_id', $depositIds);
                    })
                    ->first();

                if ($sweep === null && ! $this->shouldSweep($group, $wallet, $settings, $valuations)) {
                    return;
                }

                if ($sweep === null) {
                    $previous = TreasurySweep::query()
                        ->where('deposit_address_id', $group->deposit_address_id)
                        ->where('status', 'failed')
                        ->latest('id')
                        ->first();

                    $sweep = TreasurySweep::query()->firstOrCreate(
                        ['deposit_address_id' => $group->deposit_address_id, 'status' => 'pending'],
                        [
                            'deposit_id' => null,
                            'deposit_ids' => $depositIds->all(),
                            'network' => $group->network,
                            'amount' => (string) $group->amount,
                            'attempts' => $previous?->attempts ?? 0,
                            'last_attempted_at' => $previous?->updated_at,
                        ],
                    );
                }

                $this->processSweep($sweep, $wallet);
            });
        }
    }

    /**
     * Charge each owner, in token units, what the treasury spent consolidating
     * their deposits (top-ups for tokens, miner fee for Bitcoin) as soon as the
     * sweep confirms, instead of waiting for a withdrawal that may never come.
     */
    public function billConsolidationCosts(): void
    {
        $this->creditRecoveredGas();

        $groups = TreasurySweep::query()
            ->where('treasury_sweeps.status', 'confirmed')
            ->whereNull('treasury_sweeps.fee_recovered_at')
            ->leftJoin('deposits', 'deposits.id', '=', 'treasury_sweeps.deposit_id')
            ->leftJoin('deposit_addresses', 'deposit_addresses.id', '=', 'treasury_sweeps.deposit_address_id')
            ->leftJoin('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
            ->selectRaw('treasury_sweeps.id as sweep_id, treasury_sweeps.network, COALESCE(deposits.user_id, customers.user_id) as owner_id')
            ->get()
            ->whereNotNull('owner_id')
            ->groupBy(fn ($row) => $row->network.':'.$row->owner_id);

        foreach ($groups as $rows) {
            $first = $rows->first();

            DB::transaction(fn () => $this->billOwner((int) $first->owner_id, $first->network));
        }
    }

    private function billOwner(int $userId, string $network): void
    {
        $cost = $this->biller->outstanding($userId, $network);

        if ($cost === null) {
            return;
        }

        $this->biller->settle($userId, $network, $cost);
    }

    /**
     * Return stranded gas recovered from a deposit address to the owner whose
     * customer held that address — the recovered TRX/native is their money
     * under real-cost pass-through, not treasury revenue.
     */
    public function creditRecoveredGas(): void
    {
        GasTopup::query()
            ->where('kind', 'recovery')
            ->where('status', 'confirmed')
            ->whereNotNull('source_address')
            ->whereNull('fee_recovered_at')
            ->chunkById(100, function ($topups): void {
                foreach ($topups as $topup) {
                    $this->creditRecoveredTopup($topup);
                }
            });
    }

    private function creditRecoveredTopup(GasTopup $topup): void
    {
        $ownerId = DepositAddress::query()
            ->withoutGlobalScope('owner')
            ->join('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
            ->where('deposit_addresses.address', $topup->source_address)
            ->where('deposit_addresses.network', $topup->network)
            ->value('customers.user_id');

        if ($ownerId === null) {
            // Unattributable recovery — mark it so it is not retried forever.
            $topup->update(['fee_recovered_at' => now()]);

            return;
        }

        $credit = $this->feeConverter->toNetworkUnits($topup->network, (string) $topup->amount);

        if ($credit === null) {
            return; // valuation missing — leave unrecovered so a later run retries
        }

        DB::transaction(function () use ($topup, $ownerId, $credit): void {
            $balance = Balance::query()->withoutGlobalScope('owner')->lockForUpdate()->firstOrCreate(
                ['user_id' => $ownerId, 'network' => $topup->network],
                ['amount' => 0],
            );
            $balance->update(['amount' => bcadd((string) $balance->amount, $credit, 8)]);

            LedgerEntry::create([
                'user_id' => $ownerId,
                'network' => $topup->network,
                'amount' => $credit,
                'reason' => 'gas_recovery_credit',
            ]);

            $topup->update(['fee_recovered_at' => now()]);
        });
    }

    private function shouldSweep(object $group, TreasuryWallet $wallet, PlatformSettings $settings, $valuations): bool
    {
        $price = (string) ($valuations->get($group->network) ?? '0');
        $threshold = (string) PlatformSettings::networkSetting($group->network)->sweep_min_usd;
        $thresholdTriggered = bccomp(bcmul((string) $group->amount, $price, 8), $threshold, 8) >= 0;
        $ageTriggered = $group->oldest_credited_at !== null
            && $group->oldest_credited_at <= now()->subDays($settings->sweep_max_age_days)->toDateTimeString();
        $withdrawalTriggered = Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->where('user_id', $group->user_id)
            ->where('network', $group->network)
            ->where(function ($query): void {
                $query->where('status', 'approved')
                    ->orWhere(fn ($query) => $query->where('status', 'pending')->where('mode', 'instant'));
            })
            ->where('gross_amount', '>', $wallet->available_funds)
            ->exists();

        return $thresholdTriggered || $ageTriggered || $withdrawalTriggered;
    }

    private function processSweep(TreasurySweep $sweep, TreasuryWallet $wallet, bool $allowPiggyback = true): void
    {
        $address = $sweep->depositAddress ?? $sweep->deposit?->depositAddress;

        if ($address === null) {
            return;
        }

        if ($sweep->tx_hash !== null) {
            $this->pollSweep($sweep, $wallet);

            return;
        }

        if ($this->inBackoff($sweep)) {
            return;
        }

        $piggybackSiblings = [];

        if (Network::isToken($sweep->network)) {
            $held = $this->broadcaster->getTokenBalance($sweep->network, (int) $address->derivation_index);

            if ($held === null) {
                return;
            }

            if (bccomp($held, (string) $sweep->amount, 8) < 0) {
                $this->recordFailure($sweep, "on_chain_balance_short: holds {$held}, sweep needs {$sweep->amount}");

                return;
            }

            if ($allowPiggyback && Network::family($sweep->network) === 'evm') {
                $piggybackSiblings = $this->findPiggybackSiblings($sweep, $address);
            }

            $ready = $this->gasTreasury->ensureGasForSweep(
                $sweep->network,
                (int) $address->derivation_index,
                $address->address,
                $sweep,
                1 + count($piggybackSiblings),
            );

            if (! $ready) {
                return;
            }
        }

        $txHash = $this->broadcaster->broadcastSweep($sweep);

        if ($txHash === null) {
            $this->recordFailure($sweep, $this->broadcasterError() ?? 'Broadcast failed');

            return;
        }

        $sweep->update(['tx_hash' => $txHash, 'error_message' => null]);

        // The shared gas top-up is already in flight — sweep each sibling token
        // on the same address now instead of waiting for its own threshold.
        foreach ($piggybackSiblings as $siblingSpec) {
            $sibling = TreasurySweep::create([
                'deposit_address_id' => $siblingSpec['address']->id,
                'deposit_ids' => $siblingSpec['deposit_ids'],
                'network' => $siblingSpec['address']->network,
                'amount' => $siblingSpec['amount'],
                'piggybacked_on_sweep_id' => $sweep->id,
            ]);
            $siblingWallet = TreasuryWallet::query()->where('network', $sibling->network)->lockForUpdate()->first();

            if ($siblingWallet !== null) {
                $this->processSweep($sibling, $siblingWallet, false);
            }
        }

        $this->pollSweep($sweep, $wallet);
    }

    /**
     * Same-chain token deposit addresses sharing this EVM address that hold
     * credited, unswept deposits — swept together so one gas top-up covers all.
     *
     * @return array<int, array{address: DepositAddress, deposit_ids: array<int>, amount: string}>
     */
    private function findPiggybackSiblings(TreasurySweep $sweep, $address): array
    {
        $siblings = [];

        $addresses = DepositAddress::query()
            ->where('address', $address->address)
            ->where('id', '!=', $address->id)
            ->whereIn('network', Network::sameChainTokens($sweep->network))
            ->get();

        foreach ($addresses as $siblingAddress) {
            if (! TreasuryWallet::query()->where('network', $siblingAddress->network)->exists()) {
                continue;
            }

            $hasSweep = TreasurySweep::query()
                ->where('deposit_address_id', $siblingAddress->id)
                ->whereIn('status', ['pending', 'broadcast'])
                ->exists();

            if ($hasSweep) {
                continue;
            }

            $deposits = Deposit::query()
                ->withoutGlobalScope('owner')
                ->where('deposit_address_id', $siblingAddress->id)
                ->where('status', 'credited')
                ->whereNull('swept_at');

            if (! $deposits->exists()) {
                continue;
            }

            $siblings[] = [
                'address' => $siblingAddress,
                'deposit_ids' => $deposits->pluck('id')->all(),
                'amount' => (string) $deposits->sum('gross_amount'),
            ];
        }

        return $siblings;
    }

    private function inBackoff(TreasurySweep $sweep): bool
    {
        if ($sweep->attempts === 0 || $sweep->last_attempted_at === null) {
            return false;
        }

        $base = (int) config('blockchain.provider_backoff.base_minutes', 2);
        $max = (int) config('blockchain.provider_backoff.max_minutes', 60);
        $wait = min($base * (2 ** ($sweep->attempts - 1)), $max);

        return $sweep->last_attempted_at->addMinutes($wait)->isFuture();
    }

    private function recordFailure(TreasurySweep $sweep, string $reason): void
    {
        $sweep->update([
            'error_message' => mb_substr($reason, 0, 255),
            'attempts' => $sweep->attempts + 1,
            'last_attempted_at' => now(),
        ]);
    }

    private function broadcasterError(): ?string
    {
        return $this->broadcaster instanceof ReportsLastError ? $this->broadcaster->lastError() : null;
    }

    private function pollSweep(TreasurySweep $sweep, TreasuryWallet $wallet): void
    {
        if ($sweep->tx_hash === null) {
            return;
        }

        $receipt = $this->broadcaster->getTransactionReceipt($sweep->network, $sweep->tx_hash);

        if ($receipt === null) {
            return;
        }

        if ($receipt['status'] === 'confirmed') {
            $received = (string) $sweep->amount;
            if (Network::isNative($sweep->network)) {
                $received = bcsub($received, (string) ($receipt['fee'] ?? '0'), 8);
                if (bccomp($received, '0', 8) < 0) {
                    $received = '0.00000000';
                }
            }
            $wallet->available_funds = bcadd((string) $wallet->available_funds, $received, 8);
            $wallet->save();

            $sweep->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            $deposits = Deposit::query()->withoutGlobalScope('owner')->where('status', 'credited')->whereNull('swept_at');
            if (! empty($sweep->deposit_ids)) {
                $deposits->whereIn('id', $sweep->deposit_ids);
            } elseif ($sweep->deposit_address_id !== null) {
                $deposits->where('deposit_address_id', $sweep->deposit_address_id);
            } else {
                $deposits->whereKey($sweep->deposit_id);
            }
            $deposits->update(['swept_at' => now()]);

            GasExpense::create([
                'network' => $sweep->network,
                'tx_hash' => $sweep->tx_hash,
                'amount' => $receipt['fee'] ?? '0.00000000',
                'expensable_type' => TreasurySweep::class,
                'expensable_id' => $sweep->id,
            ]);

            return;
        }

        if ($receipt['status'] === 'failed') {
            $sweep->update([
                'status' => 'failed',
                'error_message' => 'Receipt failed',
                'attempts' => $sweep->attempts + 1,
                'last_attempted_at' => now(),
            ]);
        }
    }
}
