<?php

declare(strict_types=1);

use App\Models\UsdValuation;
use App\Services\Blockchain\PriceFeed\PriceFeedProvider;
use App\Services\Blockchain\UsdValuationUpdater;

function priceFeed(array $prices): PriceFeedProvider
{
    return new class($prices) implements PriceFeedProvider
    {
        public function __construct(private readonly array $prices) {}

        public function prices(): array
        {
            return $this->prices;
        }
    };
}

test('it updates existing valuations without creating duplicates', function () {
    UsdValuation::create(['network' => 'bitcoin', 'conversion_value' => 10000]);

    $updater = new UsdValuationUpdater(priceFeed([
        'bitcoin' => 65000.25,
        'usdt_trc20' => 1,
        'usdt_erc20' => 1,
        'usdt_base' => 1,
    ]));

    $updater->update();
    $updater->update();

    expect(UsdValuation::query()->where('network', 'bitcoin')->value('conversion_value'))->toBe('65000.250000')
        ->and(UsdValuation::query()->count())->toBe(4);
});

test('it creates all supported valuations and stores zero for missing prices', function () {
    (new UsdValuationUpdater(priceFeed(['bitcoin' => 64000])))->update();

    expect(UsdValuation::query()->pluck('conversion_value', 'network')->all())->toBe([
        'bitcoin' => '64000.000000',
        'usdt_trc20' => '0.000000',
        'usdt_erc20' => '0.000000',
        'usdt_base' => '0.000000',
    ]);
});
