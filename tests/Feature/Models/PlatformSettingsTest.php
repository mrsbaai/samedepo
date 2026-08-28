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

    expect($settings->global_deposit_fee_percent)->toBe('1.00')
        ->and($settings->default_withdrawal_mode)->toBe('approval')
        ->and($settings->api_requests_per_minute)->toBe(60)
        ->and($settings->confirmations_bitcoin)->toBeGreaterThan(0)
        ->and($settings->confirmations_usdt_trc20)->toBeGreaterThan(0)
        ->and($settings->confirmations_usdt_erc20)->toBeGreaterThan(0);
});
