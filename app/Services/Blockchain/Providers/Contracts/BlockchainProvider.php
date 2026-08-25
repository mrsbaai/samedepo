<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers\Contracts;

use App\Services\Blockchain\ValueObjects\BlockchainTransaction;

interface BlockchainProvider
{
    /**
     * @param  array<int, string>  $addresses
     * @return array<int, BlockchainTransaction>
     */
    public function fetchTransactions(array $addresses): array;

    public function network(): string;
}
