<?php

declare(strict_types=1);

use App\Services\Blockchain\PriceFeed\CoinGeckoProvider;
use Illuminate\Support\Facades\Http;

it('fetches and maps CoinGecko prices', function () {
    Http::fake([
        'api.coingecko.com/*' => Http::response([
            'bitcoin' => ['usd' => 65432.12],
            'tether' => ['usd' => 1.001],
            'tron' => ['usd' => 0.33],
            'ethereum' => ['usd' => 2000],
        ]),
    ]);

    $prices = (new CoinGeckoProvider)->prices();

    expect($prices)->toBe([
        'bitcoin' => 65432.12,
        'usdt_trc20' => 1.001,
        'usdt_erc20' => 1.001,
        'litecoin' => 0,
        'ethereum' => 2000,
        'usdc_erc20' => 0,
        'usdt_bep20' => 1.001,
        'usdc_bep20' => 0,
        'bnb' => 0,
        'native_eth' => 2000,
        'native_trx' => 0.33,
        'native_bnb' => 0,
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin%2Ctether%2Clitecoin%2Cethereum%2Cusd-coin%2Cbinancecoin%2Ctron&vs_currencies=usd');
});

it('uses the configured API key when present', function () {
    config(['blockchain.price_feed.api_key' => 'demo-key']);
    Http::fake(['api.coingecko.com/*' => Http::response([])]);

    (new CoinGeckoProvider)->prices();

    Http::assertSent(fn ($request) => $request->hasHeader('x-cg-demo-api-key', 'demo-key'));
});
