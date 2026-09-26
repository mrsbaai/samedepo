<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

class FallbackBlockchainProvider implements BlockchainProvider
{
    public function __construct(
        private readonly BlockchainProvider $primary,
        private readonly BlockchainProvider $fallback,
    ) {}

    public function network(): string
    {
        return $this->primary->network();
    }

    public function primary(): BlockchainProvider
    {
        return $this->primary;
    }

    public function fallback(): BlockchainProvider
    {
        return $this->fallback;
    }

    public function fetchTransactions(array $addresses): array
    {
        try {
            return $this->primary->fetchTransactions($addresses);
        } catch (Throwable $exception) {
            Log::warning('Blockchain provider failed; using fallback.', [
                'network' => $this->network(),
                'primary' => $this->primary::class,
                'fallback' => $this->fallback::class,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback->fetchTransactions($addresses);
        }
    }
}
