<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;

class NullBlockchainProvider implements BlockchainProvider
{
    public function __construct(private readonly string $network) {}

    public function fetchTransactions(array $addresses): array
    {
        return [];
    }

    public function network(): string
    {
        return $this->network;
    }
}
