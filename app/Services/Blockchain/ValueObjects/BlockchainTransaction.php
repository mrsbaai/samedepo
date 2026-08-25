<?php

declare(strict_types=1);

namespace App\Services\Blockchain\ValueObjects;

final class BlockchainTransaction
{
    public function __construct(
        public readonly string $network,
        public readonly string $txHash,
        public readonly string $toAddress,
        public readonly string $amount,
        public readonly int $confirmations,
        public readonly ?string $tokenContract = null,
    ) {}
}
