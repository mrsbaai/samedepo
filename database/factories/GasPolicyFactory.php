<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GasPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GasPolicy>
 */
class GasPolicyFactory extends Factory
{
    protected $model = GasPolicy::class;

    public function definition(): array
    {
        return [
            'network' => 'usdt_erc20',
            'reserve_threshold' => '0.01000000',
            'top_up_amount' => '0.02000000',
            'max_top_up' => '0.10000000',
            'manual_paused' => false,
            'alert_cooldown' => 60,
        ];
    }
}
