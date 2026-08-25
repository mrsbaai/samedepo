<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TreasuryWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TreasuryWallet>
 */
class TreasuryWalletFactory extends Factory
{
    protected $model = TreasuryWallet::class;

    public function definition(): array
    {
        return [
            'network' => fake()->randomElement(['bitcoin', 'usdt_trc20', 'usdt_erc20']),
            'derivation_index' => 0,
            'address' => fake()->uuid(),
            'available_funds' => fake()->randomFloat(8, 0, 100),
        ];
    }
}
