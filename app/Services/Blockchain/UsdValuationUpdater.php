<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\UsdValuation;
use App\Services\Blockchain\PriceFeed\PriceFeedProvider;
use App\Support\Network;

class UsdValuationUpdater
{
    public function __construct(private readonly PriceFeedProvider $provider) {}

    public function update(): void
    {
        $prices = $this->provider->prices();

        foreach (Network::valuationKeys() as $network) {
            UsdValuation::updateOrCreate(
                ['network' => $network],
                ['conversion_value' => Network::isStablecoin($network) ? 1 : ($prices[$network] ?? 0)],
            );
        }
    }
}
