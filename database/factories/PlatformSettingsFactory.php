<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformSettings;
use App\Support\Network;
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
            'sweep_max_age_days' => 30,
            'withdrawal_fee_buffer_percent' => 20.00,
            'profit_payout_warn_fee_percent' => 1.00,
            'profit_payout_block_fee_percent' => 5.00,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (): void {
            foreach (Network::keys() as $key) {
                PlatformSettings::networkSetting($key);
            }
        });
    }
}
