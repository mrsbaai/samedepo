<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

interface SpeedsUpTransactions
{
    /**
     * Ask the signer to re-sign a stuck EVM transaction at a higher gas price.
     * Returns the signer's result array (status: replaced|mined|not_found|
     * not_replaceable|insufficient_gas) or null when the call failed.
     */
    public function speedUp(string $network, int $index, string $txHash): ?array;
}
