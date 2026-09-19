<?php

declare(strict_types=1);

use App\Models\PlatformSettings;
use Database\Seeders\PlatformSettingsSeeder;

test('the platform settings seeder creates the singleton row', function () {
    (new PlatformSettingsSeeder)->run();

    expect(PlatformSettings::count())->toBe(1)
        ->and(PlatformSettings::first()->id)->toBe(1);
});

test('the platform settings row has sensible defaults', function () {
    (new PlatformSettingsSeeder)->run();

    $settings = PlatformSettings::first();

    expect($settings->global_deposit_fee_percent)->toBe('2.00')
        ->and($settings->default_withdrawal_mode)->toBe('approval')
        ->and($settings->api_requests_per_minute)->toBe(60)
        ->and($settings->sweep_max_age_days)->toBe(30)
        ->and($settings->withdrawal_fee_buffer_percent)->toBe('20.00')
        ->and($settings->profit_payout_warn_fee_percent)->toBe('1.00')
        ->and($settings->profit_payout_block_fee_percent)->toBe('5.00');

    expect(PlatformSettings::networkSetting('bitcoin')->sweep_min_usd)->toBe('200.00')
        ->and(PlatformSettings::networkSetting('usdt_trc20')->sweep_min_usd)->toBe('25.00')
        ->and(PlatformSettings::networkSetting('usdt_erc20')->sweep_min_usd)->toBe('300.00')
        ->and(PlatformSettings::networkSetting('bitcoin')->profit_address)->toBeNull()
        ->and(PlatformSettings::networkSetting('usdt_trc20')->profit_address)->toBeNull()
        ->and(PlatformSettings::networkSetting('usdt_erc20')->profit_address)->toBeNull();
});
