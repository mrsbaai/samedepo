<?php

use App\Models\NetworkSetting;
use App\Models\PlatformSettings;

test('networkSetting returns existing row', function () {
    NetworkSetting::create([
        'network' => 'bitcoin',
        'min_deposit' => '0.00100000',
        'withdrawal_min_usd' => '42.00',
        'sweep_min_usd' => '77.00',
        'profit_address' => null,
    ]);

    $setting = PlatformSettings::networkSetting('bitcoin');

    expect($setting->min_deposit)->toBe('0.00100000')
        ->and($setting->withdrawal_min_usd)->toBe('42.00')
        ->and($setting->sweep_min_usd)->toBe('77.00');
});

test('networkSetting creates the row with registry defaults when missing', function () {
    $setting = PlatformSettings::networkSetting('usdc_erc20');

    expect($setting)->toBeInstanceOf(NetworkSetting::class)
        ->and($setting->exists)->toBeTrue()
        ->and($setting->sweep_min_usd)->toBe('300.00')
        ->and($setting->min_deposit)->toBe('10.00000000');

    expect(NetworkSetting::where('network', 'usdc_erc20')->count())->toBe(1);
});

test('networkSetting creates rows for every network key', function () {
    foreach (['litecoin', 'ethereum', 'usdt_bep20', 'usdc_bep20'] as $key) {
        $setting = PlatformSettings::networkSetting($key);

        expect($setting->exists)->toBeTrue();
    }

    expect(NetworkSetting::where('network', 'litecoin')->value('sweep_min_usd'))->toBe('10.00')
        ->and(NetworkSetting::where('network', 'ethereum')->value('sweep_min_usd'))->toBe('50.00')
        ->and(NetworkSetting::where('network', 'litecoin')->value('min_deposit'))->toBe('0.05000000')
        ->and(NetworkSetting::where('network', 'ethereum')->value('min_deposit'))->toBe('0.00500000');
});
