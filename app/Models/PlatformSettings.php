<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Network;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'global_deposit_fee_percent',
        'default_withdrawal_mode',
        'api_requests_per_minute',
        'sweep_max_age_days',
        'withdrawal_fee_buffer_percent',
        'profit_payout_warn_fee_percent',
        'profit_payout_block_fee_percent',
    ];

    protected function casts(): array
    {
        return [
            'global_deposit_fee_percent' => 'decimal:2',
            'api_requests_per_minute' => 'integer',
            'sweep_max_age_days' => 'integer',
            'withdrawal_fee_buffer_percent' => 'decimal:2',
            'profit_payout_warn_fee_percent' => 'decimal:2',
            'profit_payout_block_fee_percent' => 'decimal:2',
        ];
    }

    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'global_deposit_fee_percent' => 2.00,
            'default_withdrawal_mode' => 'approval',
            'api_requests_per_minute' => 60,
            'sweep_max_age_days' => 30,
            'withdrawal_fee_buffer_percent' => 20.00,
            'profit_payout_warn_fee_percent' => 1.00,
            'profit_payout_block_fee_percent' => 5.00,
        ]);
    }

    /**
     * Per-network settings (minimum deposit, withdrawal/sweep minimums,
     * profit address) live in network_settings. Missing rows are created
     * from the Feature 023 defaults.
     */
    public static function networkSetting(string $network): NetworkSetting
    {
        return NetworkSetting::firstOrCreate(
            ['network' => $network],
            self::networkSettingDefaults($network),
        );
    }

    private static function networkSettingDefaults(string $network): array
    {
        $defaults = Network::get($network)['settings'] ?? [];

        return [
            'min_deposit' => $defaults['min_deposit'] ?? '0.00000000',
            'withdrawal_min_usd' => $defaults['withdrawal_min_usd'] ?? '0.00',
            'sweep_min_usd' => $defaults['sweep_min_usd'] ?? '0.00',
            'profit_address' => null,
        ];
    }
}
