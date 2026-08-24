<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Balance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Balance>
 */
class BalanceFactory extends Factory
{
    protected $model = Balance::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'network' => fake()->randomElement(['bitcoin', 'usdt_trc20', 'usdt_erc20']),
            'amount' => fake()->randomFloat(8, 0, 10),
        ];
    }
}
