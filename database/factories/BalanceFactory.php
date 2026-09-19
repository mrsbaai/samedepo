<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Balance;
use App\Models\User;
use App\Support\Network;
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
            'network' => fake()->randomElement(Network::keys()),
            'amount' => fake()->randomFloat(8, 0, 10),
        ];
    }
}
