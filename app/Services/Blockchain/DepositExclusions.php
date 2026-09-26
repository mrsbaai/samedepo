<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\GasTopup;
use App\Models\TreasuryWallet;

/**
 * Transfers that can never be a customer deposit: anything sent from a
 * treasury wallet, or a hash matching a known gas top-up.
 */
final class DepositExclusions
{
    /**
     * @return array<string, bool>
     */
    public static function treasuryAddresses(): array
    {
        return TreasuryWallet::query()
            ->pluck('address')
            ->mapWithKeys(fn (?string $address) => $address !== null ? [strtolower($address) => true] : [])
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    public static function topupHashes(): array
    {
        return GasTopup::query()
            ->whereNotNull('tx_hash')
            ->pluck('tx_hash')
            ->mapWithKeys(fn (string $hash) => [strtolower($hash) => true])
            ->all();
    }
}
