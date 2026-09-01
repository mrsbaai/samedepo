<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\GasExpense;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\UsdValuation;

class FeeConverter
{
    public function bufferedNativeFee(string $estimatedFeeNative): string
    {
        $bufferPercent = (string) PlatformSettings::instance()->withdrawal_fee_buffer_percent;

        return bcmul(
            $estimatedFeeNative,
            bcdiv(bcadd('100', $bufferPercent, 8), '100', 8),
            8,
        );
    }

    public function toNetworkUnits(string $network, string $nativeAmount): ?string
    {
        if ($network === 'bitcoin') {
            return $nativeAmount;
        }

        $nativeKey = $network === 'usdt_trc20' ? 'native_trx' : 'native_eth';
        $nativeUsd = UsdValuation::query()->where('network', $nativeKey)->value('conversion_value');
        $tokenUsd = UsdValuation::query()->where('network', $network)->value('conversion_value');

        if ($nativeUsd === null || $tokenUsd === null
            || bccomp((string) $nativeUsd, '0', 8) <= 0
            || bccomp((string) $tokenUsd, '0', 8) <= 0) {
            return null;
        }

        return bcdiv(bcmul($nativeAmount, (string) $nativeUsd, 8), (string) $tokenUsd, 8);
    }

    public function unrecoveredSweepGasNative(int $userId, string $network): string
    {
        $sum = GasExpense::query()
            ->where('gas_expenses.expensable_type', TreasurySweep::class)
            ->join('treasury_sweeps', 'treasury_sweeps.id', '=', 'gas_expenses.expensable_id')
            ->where('treasury_sweeps.network', $network)
            ->where('treasury_sweeps.status', 'confirmed')
            ->whereNull('treasury_sweeps.fee_recovered_at')
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
            ->sum('gas_expenses.amount');

        return $sum !== null ? (string) $sum : '0.00000000';
    }

    public function estimate(string $network, string $estimatedFeeNative, int $userId): ?array
    {
        $networkFee = $this->toNetworkUnits($network, $this->bufferedNativeFee($estimatedFeeNative));
        $recovery = $this->toNetworkUnits($network, $this->unrecoveredSweepGasNative($userId, $network));

        if ($networkFee === null || $recovery === null) {
            return null;
        }

        return [
            'network_fee' => $networkFee,
            'sweep_recovery' => $recovery,
            'total_fee' => bcadd($networkFee, $recovery, 8),
        ];
    }
}
