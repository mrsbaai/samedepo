<?php

use App\Providers\AppServiceProvider;
use App\Services\Blockchain\Providers\BlockCypherProvider;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\Providers\EsploraProvider;
use App\Services\Blockchain\Providers\EtherscanNativeProvider;
use App\Services\Blockchain\Providers\EvmLogsProvider;
use App\Services\Blockchain\Providers\FallbackBlockchainProvider;
use App\Services\Blockchain\Providers\NodeRealNativeProvider;
use App\Services\Blockchain\Providers\TronGridProvider;
use App\Services\Blockchain\Providers\TronscanProvider;

function makeNetworkProvider(string $network): BlockchainProvider
{
    $sp = new AppServiceProvider(app());
    $method = new ReflectionMethod($sp, 'makeBlockchainProvider');

    return $method->invoke($sp, $network);
}

test('networks with a configured fallback are wrapped in the fallback provider', function (string $network, string $primaryClass, string $fallbackClass) {
    $provider = makeNetworkProvider($network);

    expect($provider)->toBeInstanceOf(FallbackBlockchainProvider::class)
        ->and($provider->network())->toBe($network)
        ->and($provider->primary())->toBeInstanceOf($primaryClass)
        ->and($provider->fallback())->toBeInstanceOf($fallbackClass);
})->with([
    'bitcoin' => ['bitcoin', EsploraProvider::class, EsploraProvider::class],
    'litecoin' => ['litecoin', EsploraProvider::class, BlockCypherProvider::class],
    'usdt_trc20' => ['usdt_trc20', TronGridProvider::class, TronscanProvider::class],
    'ethereum' => ['ethereum', EtherscanNativeProvider::class, EtherscanNativeProvider::class],
    'usdt_erc20' => ['usdt_erc20', EvmLogsProvider::class, EvmLogsProvider::class],
    'usdc_erc20' => ['usdc_erc20', EvmLogsProvider::class, EvmLogsProvider::class],
    'usdt_bep20' => ['usdt_bep20', EvmLogsProvider::class, EvmLogsProvider::class],
    'usdc_bep20' => ['usdc_bep20', EvmLogsProvider::class, EvmLogsProvider::class],
]);

test('bnb uses the nodereal native provider without a fallback', function () {
    $provider = makeNetworkProvider('bnb');

    expect($provider)->toBeInstanceOf(NodeRealNativeProvider::class)
        ->and($provider->network())->toBe('bnb');
});

test('a fallback without a usable url leaves the primary unwrapped', function () {
    config(['networks.networks.bitcoin.fallback_provider' => [
        'driver' => 'esplora',
        'base_url' => '',
    ]]);

    expect(makeNetworkProvider('bitcoin'))->toBeInstanceOf(EsploraProvider::class);
});

test('a fallback without a driver leaves the primary unwrapped', function () {
    config(['networks.networks.bitcoin.fallback_provider' => [
        'base_url' => 'https://blockstream.info/api',
    ]]);

    expect(makeNetworkProvider('bitcoin'))->toBeInstanceOf(EsploraProvider::class);
});
