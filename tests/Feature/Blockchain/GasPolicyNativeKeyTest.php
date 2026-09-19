<?php

use App\Models\GasPolicy;
use App\Services\Blockchain\GasTreasuryService;

test('gas policy resolves by native key for token networks', function () {
    GasPolicy::create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.10000000',
        'manual_paused' => false,
        'alert_cooldown' => 60,
    ]);

    $service = app(GasTreasuryService::class);

    $viaToken = $service->policy('usdt_erc20');
    $viaUsdc = $service->policy('usdc_erc20');
    $viaNative = $service->policy('native_eth');

    expect($viaToken->id)->toBe($viaNative->id)
        ->and($viaUsdc->id)->toBe($viaNative->id);
});

test('gas policy resolves by native key for tron', function () {
    GasPolicy::create([
        'network' => 'native_trx',
        'reserve_threshold' => '40.00000000',
        'top_up_amount' => '20.00000000',
        'max_top_up' => '300.00000000',
        'manual_paused' => false,
        'alert_cooldown' => 60,
    ]);

    $policy = app(GasTreasuryService::class)->policy('usdt_trc20');

    expect($policy->network)->toBe('native_trx');
});
