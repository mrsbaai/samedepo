<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

interface SimulatesTransactions
{
    /**
     * Build and sign a transaction on the signer without broadcasting it.
     * `action` is one of withdraw|sweep|topup; `payload` is the same body the
     * live endpoint would receive. Returns
     * ['ok' => bool, 'tx_hash' => ?string, 'checks' => array, 'error' => ?string].
     */
    public function dryRun(string $action, array $payload): array;
}
