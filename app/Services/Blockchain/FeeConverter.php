<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\EnergyRental;
use App\Models\GasExpense;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\UsdValuation;
use App\Support\Network;
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
        if (Network::isNative($network)) {
            return $nativeAmount;
        }

        $nativeKey = Network::nativeKey($network);
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
        if (Network::isNative($network)) {
            $query = $this->attributableSweepGasQuery($userId, $network)
                ->when($unrecoveredOnly, fn (Builder $query) => $query->whereNull('treasury_sweeps.fee_recovered_at'));

            return $this->sumAttributableSweepGas($query);
        }

        $sum = $this->attributableTopupQuery($userId, $network)
            ->when($unrecoveredOnly, fn (Builder $query) => $query->whereNull('gas_topups.fee_recovered_at'))
            ->leftJoin('gas_expenses', 'gas_expenses.gas_topup_id', '=', 'gas_topups.id')
            ->selectRaw('COALESCE(SUM(gas_topups.amount + COALESCE(gas_expenses.amount, 0)), 0) as total')
            ->value('total');

        $rentalSum = $this->attributableRentalQuery($userId, $network)
            ->when($unrecoveredOnly, fn (Builder $query) => $query->whereNull('energy_rentals.fee_recovered_at'))
            ->sum('energy_rentals.cost_native');

        return bcadd((string) $sum, (string) $rentalSum, 8);
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
            ->where('gas_topups.kind', 'topup')
            ->join('deposit_addresses', function ($join): void {
                $join->on('deposit_addresses.address', '=', 'gas_topups.recipient_address')
                    ->on('deposit_addresses.network', '=', 'gas_topups.network');
            })
            ->join('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
            ->where('customers.user_id', $userId)
            // Top-ups the platform paid for a forfeited sweep are never billable.
            ->leftJoin('treasury_sweeps', 'treasury_sweeps.id', '=', 'gas_topups.treasury_sweep_id')
            ->where(fn (Builder $query) => $query
                ->whereNull('gas_topups.treasury_sweep_id')
                ->orWhere('treasury_sweeps.platform_paid', false));
    }

    /**
     * @return Builder<EnergyRental>
     */
    public function attributableRentalQuery(int $userId, string $network): Builder
    {
        return EnergyRental::query()
            // filled or expired — expiry only means the rental period lapsed,
            // the cost was still paid and remains billable.
            ->whereIn('energy_rentals.status', ['filled', 'expired'])
            ->where('energy_rentals.purposable_type', (new TreasurySweep)->getMorphClass())
            ->join('treasury_sweeps', 'treasury_sweeps.id', '=', 'energy_rentals.purposable_id')
            ->where('treasury_sweeps.network', $network)
            ->where('treasury_sweeps.status', 'confirmed')
            ->where('treasury_sweeps.platform_paid', false)
            ->where(function ($query) use ($userId): void {
                $query->whereExists(function ($sub) use ($userId): void {
                    $sub->selectRaw('1')
                        ->from('deposits')
                        ->whereColumn('deposits.id', 'treasury_sweeps.deposit_id')
                        ->where('deposits.user_id', $userId);
                })->orWhereExists(function ($sub) use ($userId): void {
                    $sub->selectRaw('1')
                        ->from('deposit_addresses')
                        ->join('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
                        ->whereColumn('deposit_addresses.id', 'treasury_sweeps.deposit_address_id')
                        ->where('customers.user_id', $userId);
                });
            });
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
            ->where('treasury_sweeps.platform_paid', false)
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
