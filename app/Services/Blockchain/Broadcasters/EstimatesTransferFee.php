<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

interface EstimatesTransferFee
{
    /**
     * Estimate the native-currency cost of a transfer. `destination` lets the
     * estimator price account activation (TRON) or first-holder token storage;
     * `sourceIndex` identifies which derived address pays.
     */
    public function estimateTransferFee(
        string $network,
        bool $tokenTransfer,
        ?string $destination = null,
        ?int $sourceIndex = null,
    ): ?string;
}
