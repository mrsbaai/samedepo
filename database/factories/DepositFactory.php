<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Deposit;
use App\Models\DepositAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deposit>
 */
class DepositFactory extends Factory
{
    protected $model = Deposit::class;

    public function definition(): array
    {
        $address = DepositAddress::factory()->create();

        return [
            'deposit_address_id' => $address->id,
            'customer_id' => $address->customer_id,
            'user_id' => $address->customer->user_id,
            'network' => $address->network,
            'tx_hash' => fake()->uuid(),
            'gross_amount' => fake()->randomFloat(8, 0, 10),
            'fee_amount' => null,
            'credited_amount' => null,
            'confirmation_count' => 0,
            'status' => fake()->randomElement(['detected', 'pending', 'credited', 'ignored']),
            'detected_at' => now(),
            'credited_at' => null,
        ];
    }
}
