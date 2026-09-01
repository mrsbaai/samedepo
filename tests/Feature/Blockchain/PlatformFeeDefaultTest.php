<?php

declare(strict_types=1);

use App\Models\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function updateDefaultDepositFeeMigration(): object
{
    $path = database_path('migrations/2026_09_01_100001_update_default_deposit_fee_to_two_percent.php');

    if (! file_exists($path)) {
        throw new RuntimeException('Migration file not found: '.$path);
    }

    return require $path;
}

test('new install default deposit fee is two percent', function () {
    $settings = PlatformSettings::instance();

    expect((string) $settings->global_deposit_fee_percent)->toBe('2.00');
});

test('data migration upgrades one percent to two percent and leaves custom rates untouched', function () {
    Schema::disableForeignKeyConstraints();

    DB::table('platform_settings')->delete();

    $timestamp = now();
    DB::table('platform_settings')->insert([
        [
            'global_deposit_fee_percent' => '1.00',
            'default_withdrawal_mode' => 'approval',
            'min_deposit_bitcoin' => '0.00010000',
            'min_deposit_usdt_trc20' => '10.00000000',
            'min_deposit_usdt_erc20' => '10.00000000',
            'withdrawal_min_usd_bitcoin' => '100.00',
            'withdrawal_min_usd_usdt_trc20' => '100.00',
            'withdrawal_min_usd_usdt_erc20' => '100.00',
            'confirmations_bitcoin' => 3,
            'confirmations_usdt_trc20' => 12,
            'confirmations_usdt_erc20' => 12,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
        [
            'global_deposit_fee_percent' => '1.50',
            'default_withdrawal_mode' => 'approval',
            'min_deposit_bitcoin' => '0.00010000',
            'min_deposit_usdt_trc20' => '10.00000000',
            'min_deposit_usdt_erc20' => '10.00000000',
            'withdrawal_min_usd_bitcoin' => '100.00',
            'withdrawal_min_usd_usdt_trc20' => '100.00',
            'withdrawal_min_usd_usdt_erc20' => '100.00',
            'confirmations_bitcoin' => 3,
            'confirmations_usdt_trc20' => 12,
            'confirmations_usdt_erc20' => 12,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ]);

    updateDefaultDepositFeeMigration()->up();

    $percents = PlatformSettings::query()->pluck('global_deposit_fee_percent')->map(fn ($value) => (string) $value)->all();

    expect($percents)->toContain('2.00')
        ->and($percents)->toContain('1.50')
        ->and($percents)->not->toContain('1.00');

    updateDefaultDepositFeeMigration()->down();

    $percents = PlatformSettings::query()->pluck('global_deposit_fee_percent')->map(fn ($value) => (string) $value)->all();

    expect($percents)->toContain('1.00')
        ->and($percents)->toContain('1.50');

    Schema::enableForeignKeyConstraints();
});
