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
            'global_deposit_fee_percent' => 2.00,
            'default_withdrawal_mode' => 'approval',
            'api_requests_per_minute' => 60,
            'min_deposit_bitcoin' => 0.00010000,
            'min_deposit_usdt_trc20' => 10.00000000,
            'min_deposit_usdt_erc20' => 10.00000000,
            'withdrawal_min_usd_bitcoin' => 100.00,
            'withdrawal_min_usd_usdt_trc20' => 100.00,
            'withdrawal_min_usd_usdt_erc20' => 100.00,
            'sweep_min_usd_bitcoin' => 200.00,
            'sweep_min_usd_usdt_trc20' => 25.00,
            'sweep_min_usd_usdt_erc20' => 300.00,
            'sweep_max_age_days' => 30,
            'withdrawal_fee_buffer_percent' => 20.00,
            'profit_address_bitcoin' => null,
            'profit_address_usdt_trc20' => null,
            'profit_address_usdt_erc20' => null,
            'profit_payout_warn_fee_percent' => 1.00,
            'profit_payout_block_fee_percent' => 5.00,
            'confirmations_bitcoin' => 3,
            'confirmations_usdt_trc20' => 12,
            'confirmations_usdt_erc20' => 12,
        ];
    }
}
