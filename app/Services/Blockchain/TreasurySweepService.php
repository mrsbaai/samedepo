<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Balance;
use App\Models\Deposit;
use App\Models\GasExpense;
use App\Models\GasTopup;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;

class TreasurySweepService
{
    public function __construct(
        private readonly BlockchainBroadcaster $broadcaster,
        private ?GasTreasuryService $gasTreasury = null,
        private ?FeeConverter $feeConverter = null,
    ) {
        $this->gasTreasury ??= new GasTreasuryService($this->broadcaster);
        $this->feeConverter ??= new FeeConverter;
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

                $sweep ??= TreasurySweep::query()->firstOrCreate(
                    ['deposit_address_id' => $group->deposit_address_id, 'status' => 'pending'],
                    [
                        'deposit_id' => null,
                        'deposit_ids' => $depositIds->all(),
                        'network' => $group->network,
                        'amount' => (string) $group->amount,
                    ],
                );

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
        $cost = $this->feeConverter->toNetworkUnits(
            $network,
            $this->feeConverter->unrecoveredSweepGasNative($userId, $network),
        );

        if ($cost === null) {
            return;
        }

        if (bccomp($cost, '0', 8) > 0) {
            // ponytail: balance may go negative if everything is already reserved for a withdrawal; the next deposit nets it out.
            $balance = Balance::query()->withoutGlobalScope('owner')->lockForUpdate()->firstOrCreate(
                ['user_id' => $userId, 'network' => $network],
                ['amount' => 0],
            );
            $balance->update(['amount' => bcsub((string) $balance->amount, $cost, 8)]);

            LedgerEntry::create([
                'user_id' => $userId,
                'network' => $network,
                'amount' => '-'.$cost,
                'reason' => 'consolidation_fee',
            ]);
        }

        $now = now();
        TreasurySweep::query()->where('network', $network)->where('status', 'confirmed')->whereNull('fee_recovered_at')
            ->where(function ($query) use ($userId): void {
                $query->whereExists(function ($sub) use ($userId): void {
                    $sub->selectRaw('1')->from('deposits')
                        ->whereColumn('deposits.id', 'treasury_sweeps.deposit_id')
                        ->where('deposits.user_id', $userId);
                })->orWhereExists(function ($sub) use ($userId): void {
                    $sub->selectRaw('1')->from('deposit_addresses')
                        ->join('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
                        ->whereColumn('deposit_addresses.id', 'treasury_sweeps.deposit_address_id')
                        ->where('customers.user_id', $userId);
                });
            })
            ->update(['fee_recovered_at' => $now]);
        $topupIds = $this->feeConverter->attributableTopupQuery($userId, $network)
            ->whereNull('gas_topups.fee_recovered_at')
            ->pluck('gas_topups.id');
        GasTopup::query()->whereIn('id', $topupIds)->update(['fee_recovered_at' => $now]);
    }

    private function shouldSweep(object $group, TreasuryWallet $wallet, PlatformSettings $settings, $valuations): bool
    {
        $price = (string) ($valuations->get($group->network) ?? '0');
        $threshold = (string) $settings->{'sweep_min_usd_'.$group->network};
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

    private function processSweep(TreasurySweep $sweep, TreasuryWallet $wallet): void
    {
        $address = $sweep->depositAddress ?? $sweep->deposit?->depositAddress;

        if ($address === null) {
            return;
        }

        if ($sweep->tx_hash !== null) {
            $this->pollSweep($sweep, $wallet);

            return;
        }

        if (in_array($sweep->network, ['usdt_erc20', 'usdt_trc20'], true)) {
            $ready = $this->gasTreasury->ensureGasForSweep(
                $sweep->network,
                (int) $address->derivation_index,
                $address->address,
            );

            if (! $ready) {
                return;
            }
        }

        $txHash = $this->broadcaster->broadcastSweep($sweep);

        if ($txHash === null) {
            $sweep->update(['error_message' => 'Broadcast failed']);

            return;
        }

        $sweep->update(['tx_hash' => $txHash]);
        $this->pollSweep($sweep, $wallet);
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
            if ($sweep->network === 'bitcoin') {
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
            ]);
        }
    }
}
