<?php

use App\Models\BlockchainScanState;
use App\Models\GasPolicy;
use App\Models\NetworkSetting;
use App\Models\TreasuryWallet;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config([
        'blockchain.bitcoin.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
        'blockchain.usdt_trc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
        'blockchain.usdt_erc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
    ]);
});

test('sync-networks creates missing rows from the registry', function () {
    Artisan::call('app:sync-networks');

    foreach (['bitcoin', 'usdt_trc20', 'usdt_erc20'] as $key) {
        expect(TreasuryWallet::where('network', $key)->exists())->toBeTrue();
        expect(NetworkSetting::where('network', $key)->exists())->toBeTrue();
        expect(BlockchainScanState::where('network', $key)->exists())->toBeTrue();
    }

    expect(GasPolicy::where('network', 'native_eth')->exists())->toBeTrue()
        ->and(GasPolicy::where('network', 'native_trx')->exists())->toBeTrue();
});

test('evm treasury wallets share derivation index 0 and the same address', function () {
    config(['networks.networks.usdc_erc20.enabled' => true]);

    Artisan::call('app:sync-networks');

    $usdt = TreasuryWallet::where('network', 'usdt_erc20')->first();
    $usdc = TreasuryWallet::where('network', 'usdc_erc20')->first();

    expect($usdt->derivation_index)->toBe(0)
        ->and($usdc->derivation_index)->toBe(0)
        ->and($usdc->address)->toBe($usdt->address);
});

test('sync-networks is idempotent', function () {
    Artisan::call('app:sync-networks');

    $counts = [
        TreasuryWallet::count(),
        NetworkSetting::count(),
        BlockchainScanState::count(),
        GasPolicy::count(),
    ];

    Artisan::call('app:sync-networks');

    expect([
        TreasuryWallet::count(),
        NetworkSetting::count(),
        BlockchainScanState::count(),
        GasPolicy::count(),
    ])->toBe($counts);
});
