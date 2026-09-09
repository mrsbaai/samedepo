<?php

declare(strict_types=1);

use App\Models\GasTopup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Old recovered sweeps had their gas cost billed on a later withdrawal,
        // but the new model bills the associated gas top-up at sweep time.
        // Mark top-ups that belong to already-recovered sweeps so they are not
        // double-billed when billConsolidationCosts() runs.
        GasTopup::query()
            ->whereNull('fee_recovered_at')
            ->where('status', 'confirmed')
            ->where(function ($query): void {
                $query->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('deposit_addresses')
                        ->join('treasury_sweeps', 'treasury_sweeps.deposit_address_id', '=', 'deposit_addresses.id')
                        ->whereColumn('deposit_addresses.address', 'gas_topups.recipient_address')
                        ->whereColumn('deposit_addresses.network', 'gas_topups.network')
                        ->whereNotNull('treasury_sweeps.fee_recovered_at');
                })->orWhereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('deposit_addresses')
                        ->join('deposits', 'deposits.deposit_address_id', '=', 'deposit_addresses.id')
                        ->join('treasury_sweeps', 'treasury_sweeps.deposit_id', '=', 'deposits.id')
                        ->whereColumn('deposit_addresses.address', 'gas_topups.recipient_address')
                        ->whereColumn('deposit_addresses.network', 'gas_topups.network')
                        ->whereNotNull('treasury_sweeps.fee_recovered_at');
                });
            })
            ->update(['fee_recovered_at' => now()]);
    }

    public function down(): void
    {
        DB::statement('UPDATE gas_topups SET fee_recovered_at = NULL');
    }
};
