<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Deposit;
use App\Models\GasExpense;
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
    ) {
        $this->gasTreasury ??= new GasTreasuryService($this->broadcaster);
    }

    public function sweep(): void
    {
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
            $wallet->available_funds = bcadd((string) $wallet->available_funds, (string) $sweep->amount, 8);
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
