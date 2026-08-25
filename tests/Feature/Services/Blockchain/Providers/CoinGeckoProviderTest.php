<?php

declare(strict_types=1);

use App\Services\Blockchain\PriceFeed\CoinGeckoProvider;
use Illuminate\Support\Facades\Http;

it('fetches and maps CoinGecko prices', function () {
    Http::fake([
        'api.coingecko.com/*' => Http::response([
            'bitcoin' => ['usd' => 65432.12],
            'tether' => ['usd' => 1.001],
        ]),
    ]);

    $prices = (new CoinGeckoProvider)->prices();

    expect($prices)->toBe([
        'bitcoin' => 65432.12,
        'usdt_trc20' => 1.001,
        'usdt_erc20' => 1.001,
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin%2Ctether&vs_currencies=usd');
});

it('uses the configured API key when present', function () {
    config(['blockchain.price_feed.api_key' => 'demo-key']);
    Http::fake(['api.coingecko.com/*' => Http::response([])]);

    (new CoinGeckoProvider)->prices();

    Http::assertSent(fn ($request) => $request->hasHeader('x-cg-demo-api-key', 'demo-key'));
});
