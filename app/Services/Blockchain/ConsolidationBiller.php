<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Balance;
use App\Models\EnergyRental;
use App\Models\GasTopup;
use App\Models\LedgerEntry;
use App\Models\TreasurySweep;

/**
 * Bills an owner, in token units, what the treasury spent consolidating their
 * deposits (top-ups for tokens, miner fee for native coins). Callers either
 * charge the owner's balance (sweep-time billing) or deduct the cost from a
 * withdrawal's amount_sent (withdrawal-time billing) — the ledger entry and
 * the fee_recovered_at marking happen in both cases.
 */
class ConsolidationBiller
{
    public function __construct(
        private readonly FeeConverter $feeConverter,
    ) {}

    /**
     * Token-unit cost of the owner's unrecovered sweep gas, or null when the
     * USD valuations needed for the conversion are unavailable.
     */
    public function outstanding(int $userId, string $network): ?string
    {
        return $this->feeConverter->toNetworkUnits(
            $network,
            $this->feeConverter->unrecoveredSweepGasNative($userId, $network),
        );
    }

    /**
     * Record the consolidation charge and mark every attributable confirmed
     * sweep, top-up and rental as recovered so the cost is never billed twice.
     * With $chargeBalance=false the cost was already taken out of amount_sent,
     * so only the ledger entry and the marking apply.
     */
    public function settle(int $userId, string $network, string $cost, ?int $withdrawalId = null, bool $chargeBalance = true): void
    {
        if (bccomp($cost, '0', 8) > 0) {
            if ($chargeBalance) {
                $balance = Balance::query()->withoutGlobalScope('owner')->lockForUpdate()->firstOrCreate(
                    ['user_id' => $userId, 'network' => $network],
                    ['amount' => 0],
                );
                $balance->update(['amount' => bcsub((string) $balance->amount, $cost, 8)]);
            }

            LedgerEntry::create([
                'user_id' => $userId,
                'network' => $network,
                'amount' => '-'.$cost,
                'reason' => 'consolidation_fee',
                'withdrawal_id' => $withdrawalId,
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
        $rentalIds = $this->feeConverter->attributableRentalQuery($userId, $network)
            ->whereNull('energy_rentals.fee_recovered_at')
            ->pluck('energy_rentals.id');
        EnergyRental::query()->whereIn('id', $rentalIds)->update(['fee_recovered_at' => $now]);
    }
}
