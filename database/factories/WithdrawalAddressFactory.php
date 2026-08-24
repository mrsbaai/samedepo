<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\WithdrawalAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WithdrawalAddress>
 */
class WithdrawalAddressFactory extends Factory
{
    protected $model = WithdrawalAddress::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'network' => fake()->randomElement(['bitcoin', 'usdt_trc20', 'usdt_erc20']),
            'address' => fake()->uuid(),
        ];
    }
}
