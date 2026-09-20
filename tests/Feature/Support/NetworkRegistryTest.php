<?php

use App\Support\Network;

test('registry defines all nine networks with required fields', function () {
    $expected = ['bitcoin', 'usdt_trc20', 'usdt_erc20', 'litecoin', 'ethereum', 'usdc_erc20', 'usdt_bep20', 'usdc_bep20', 'bnb'];

    expect(Network::keys())->toBe($expected);

    foreach ($expected as $key) {
        $network = Network::get($key);

        foreach (['label', 'symbol', 'decimals', 'family', 'chain', 'kind', 'native_key', 'contract', 'token_decimals', 'confirmations', 'scan_interval', 'provider', 'coingecko_id', 'explorer_tx', 'address_group', 'xpub', 'enabled'] as $field) {
            expect($network)->toHaveKey($field);
        }
    }
});

test('only the three legacy networks are enabled by default', function () {
    expect(Network::enabledKeys())->toBe(['bitcoin', 'usdt_trc20', 'usdt_erc20']);
});

test('enabled flag is read from the registry', function () {
    config(['networks.networks.litecoin.enabled' => true]);

    expect(Network::enabledKeys())->toContain('litecoin');
});

test('helpers resolve network metadata', function () {
    expect(Network::label('bitcoin'))->toBe('Bitcoin')
        ->and(Network::symbol('usdt_trc20'))->toBe('USDT')
        ->and(Network::decimals('usdt_erc20'))->toBe(2)
        ->and(Network::family('usdc_bep20'))->toBe('evm')
        ->and(Network::chain('usdt_bep20'))->toBe('bsc')
        ->and(Network::kind('ethereum'))->toBe('native')
        ->and(Network::nativeKey('usdc_erc20'))->toBe('native_eth')
        ->and(Network::nativeKey('native_trx'))->toBe('native_trx')
        ->and(Network::contract('usdt_erc20'))->toBe('0xdAC17F958D2ee523a2206206994597C13D831ec7')
        ->and(Network::tokenDecimals('usdt_bep20'))->toBe(18)
        ->and(Network::confirmations('usdt_trc20'))->toBe(20)
        ->and(Network::scanInterval('bitcoin'))->toBe(15)
        ->and(Network::explorerTx('litecoin'))->toBe('https://litecoinspace.org/tx/{hash}')
        ->and(Network::addressGroup('usdc_bep20'))->toBe('evm')
        ->and(Network::coingeckoId('usdc_erc20'))->toBe('usd-coin')
        ->and(Network::coingeckoId('native_bnb'))->toBe('binancecoin')
        ->and(Network::coingeckoId('bnb'))->toBe('binancecoin')
        ->and(Network::isNative('bnb'))->toBeTrue()
        ->and(Network::isEvm('bnb'))->toBeTrue()
        ->and(Network::chain('bnb'))->toBe('bsc')
        ->and(Network::nativeKey('bnb'))->toBe('native_bnb')
        ->and(Network::addressGroup('bnb'))->toBe('evm');
});

test('type predicates', function () {
    expect(Network::isToken('usdt_erc20'))->toBeTrue()
        ->and(Network::isToken('bitcoin'))->toBeFalse()
        ->and(Network::isNative('bitcoin'))->toBeTrue()
        ->and(Network::isNative('litecoin'))->toBeTrue()
        ->and(Network::isEvm('usdc_bep20'))->toBeTrue()
        ->and(Network::isEvm('usdt_trc20'))->toBeFalse();
});

test('sameChainTokens returns the tokens on the same chain', function () {
    expect(Network::sameChainTokens('ethereum'))->toBe(['usdt_erc20', 'usdc_erc20'])
        ->and(Network::sameChainTokens('usdt_bep20'))->toBe(['usdt_bep20', 'usdc_bep20'])
        ->and(Network::sameChainTokens('bitcoin'))->toBe([]);
});

test('present returns the UI metadata shape', function () {
    expect(Network::present('usdc_erc20'))->toBe([
        'key' => 'usdc_erc20',
        'label' => 'USDC (ERC20)',
        'symbol' => 'USDC',
        'decimals' => 2,
        'slug' => 'usdc-erc20',
        'chart_color' => 'sky-400',
        'icon' => 'usdc',
        'badge' => 'eth',
    ]);
});

test('valuationKeys returns all network keys plus distinct native keys', function () {
    expect(Network::valuationKeys())->toBe([
        'bitcoin', 'usdt_trc20', 'usdt_erc20', 'litecoin', 'ethereum',
        'usdc_erc20', 'usdt_bep20', 'usdc_bep20', 'bnb',
        'native_eth', 'native_trx', 'native_bnb',
    ]);
});

test('unknown key throws RuntimeException', function () {
    Network::get('solana');
})->throws(RuntimeException::class, 'Unknown network: solana');

test('helper on unknown key throws RuntimeException', function () {
    Network::symbol('dogecoin');
})->throws(RuntimeException::class);
