<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSettings>
 */
class PlatformSettingsFactory extends Factory
{
    protected $model = PlatformSettings::class;

    public function definition(): array
    {
        return [
            'global_deposit_fee_percent' => 1.00,
            'default_withdrawal_mode' => 'approval',
            'api_requests_per_minute' => 60,
            'min_deposit_bitcoin' => 0.00010000,
            'min_deposit_usdt_trc20' => 10.00000000,
            'min_deposit_usdt_erc20' => 10.00000000,
            'withdrawal_min_usd_bitcoin' => 50.00,
            'withdrawal_min_usd_usdt_trc20' => 50.00,
            'withdrawal_min_usd_usdt_erc20' => 50.00,
            'confirmations_bitcoin' => 3,
            'confirmations_usdt_trc20' => 12,
            'confirmations_usdt_erc20' => 12,
        ];
    }
}
