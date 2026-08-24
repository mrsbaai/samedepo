<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Withdrawal>
 */
class WithdrawalFactory extends Factory
{
    protected $model = Withdrawal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'network' => fake()->randomElement(['bitcoin', 'usdt_trc20', 'usdt_erc20']),
            'gross_amount' => fake()->randomFloat(8, 0, 10),
            'network_fee' => null,
            'amount_sent' => null,
            'destination_address' => fake()->uuid(),
            'mode' => fake()->randomElement(['instant', 'approval']),
            'status' => fake()->randomElement(['pending', 'approved', 'denied', 'cancelled', 'sent']),
            'tx_hash' => null,
            'decided_at' => null,
            'decided_by' => null,
            'sent_at' => null,
        ];
    }
}
