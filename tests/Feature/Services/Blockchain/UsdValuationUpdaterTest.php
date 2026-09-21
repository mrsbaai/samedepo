<?php

declare(strict_types=1);

use App\Models\UsdValuation;
use App\Services\Blockchain\PriceFeed\PriceFeedProvider;
use App\Services\Blockchain\UsdValuationUpdater;
use App\Support\Network;

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
        'native_trx' => 0.33,
        'native_eth' => 2000,
    ]));

    $updater->update();
    $updater->update();

    expect(UsdValuation::query()->where('network', 'bitcoin')->value('conversion_value'))->toBe('65000.250000')
        ->and(UsdValuation::query()->count())->toBe(count(Network::valuationKeys()));
});

test('it creates all supported valuations and stores zero for missing prices', function () {
    (new UsdValuationUpdater(priceFeed(['bitcoin' => 64000])))->update();

    $expected = array_fill_keys(Network::valuationKeys(), '0.000000');
    $expected['bitcoin'] = '64000.000000';

    foreach (['usdt_trc20', 'usdt_erc20', 'usdt_bep20', 'usdc_erc20', 'usdc_bep20'] as $key) {
        $expected[$key] = '1.000000';
    }

    expect(UsdValuation::query()->pluck('conversion_value', 'network')->all())->toBe($expected);
});

test('stablecoins are pinned to one regardless of the feed price', function () {
    (new UsdValuationUpdater(priceFeed([
        'bitcoin' => 65000.25,
        'usdt_trc20' => 0.999736,
        'usdt_erc20' => 0.999736,
        'usdt_bep20' => 0.999736,
        'usdc_erc20' => 0.999787,
        'usdc_bep20' => 0.999787,
    ])))->update();

    $valuations = UsdValuation::query()->pluck('conversion_value', 'network')->all();

    foreach (['usdt_trc20', 'usdt_erc20', 'usdt_bep20', 'usdc_erc20', 'usdc_bep20'] as $key) {
        expect($valuations[$key])->toBe('1.000000');
    }

    expect($valuations['bitcoin'])->toBe('65000.250000');
});
