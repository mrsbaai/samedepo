<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\UsdValuation;
use App\Services\Blockchain\PriceFeed\PriceFeedProvider;

class UsdValuationUpdater
{
    private const NETWORKS = ['bitcoin', 'usdt_trc20', 'usdt_erc20'];

    public function __construct(private readonly PriceFeedProvider $provider) {}

    public function update(): void
    {
        $prices = $this->provider->prices();

        foreach (self::NETWORKS as $network) {
            UsdValuation::updateOrCreate(
                ['network' => $network],
                ['conversion_value' => $prices[$network] ?? 0],
            );
        }
    }
}
