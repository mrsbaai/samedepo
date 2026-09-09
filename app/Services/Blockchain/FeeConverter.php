<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\GasExpense;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\UsdValuation;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Native units the treasury actually spent consolidating an owner's deposits.
     * Tokens: gas top-ups sent to deposit addresses (amount + top-up tx fee).
     * Bitcoin: the miner fee taken out of each sweep.
     */
    public function sweepGasNative(int $userId, string $network, bool $unrecoveredOnly = false): string
    {
        if ($network === 'bitcoin') {
            $query = $this->attributableSweepGasQuery($userId, $network)
                ->when($unrecoveredOnly, fn (Builder $query) => $query->whereNull('treasury_sweeps.fee_recovered_at'));

            return $this->sumAttributableSweepGas($query);
        }

        $sum = $this->attributableTopupQuery($userId, $network)
            ->when($unrecoveredOnly, fn (Builder $query) => $query->whereNull('gas_topups.fee_recovered_at'))
            ->leftJoin('gas_expenses', 'gas_expenses.gas_topup_id', '=', 'gas_topups.id')
            ->selectRaw('COALESCE(SUM(gas_topups.amount + COALESCE(gas_expenses.amount, 0)), 0) as total')
            ->value('total');

        return bcadd((string) $sum, '0', 8);
    }

    public function unrecoveredSweepGasNative(int $userId, string $network): string
    {
        return $this->sweepGasNative($userId, $network, true);
    }

    /**
     * @return Builder<GasTopup>
     */
    public function attributableTopupQuery(int $userId, string $network): Builder
    {
        return GasTopup::query()
            ->where('gas_topups.network', $network)
            ->where('gas_topups.status', 'confirmed')
            ->join('deposit_addresses', function ($join): void {
                $join->on('deposit_addresses.address', '=', 'gas_topups.recipient_address')
                    ->on('deposit_addresses.network', '=', 'gas_topups.network');
            })
            ->join('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
            ->where('customers.user_id', $userId);
    }

    /**
     * @return Builder<GasExpense>
     */
    public function attributableSweepGasQuery(int $userId, string $network): Builder
    {
        return GasExpense::query()
            ->where('gas_expenses.expensable_type', TreasurySweep::class)
            ->join('treasury_sweeps', 'treasury_sweeps.id', '=', 'gas_expenses.expensable_id')
            ->where('treasury_sweeps.network', $network)
            ->where('treasury_sweeps.status', 'confirmed')
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
            });
    }

    private function sumAttributableSweepGas(Builder $query): string
    {
        $sum = $query->sum('gas_expenses.amount');

        return bcadd($sum !== null ? (string) $sum : '0', '0', 8);
    }

    public function estimate(string $network, string $estimatedFeeNative): ?array
    {
        $networkFee = $this->toNetworkUnits($network, $this->bufferedNativeFee($estimatedFeeNative));

        return $networkFee === null ? null : ['network_fee' => $networkFee, 'total_fee' => $networkFee];
    }
}
