<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'network' => fake()->randomElement(['bitcoin', 'usdt_trc20', 'usdt_erc20']),
            'amount' => fake()->randomFloat(8, -10, 10),
            'reason' => fake()->randomElement(['deposit_credit', 'fee', 'withdrawal_reserve', 'withdrawal_send', 'withdrawal_return']),
            'deposit_id' => null,
            'withdrawal_id' => null,
        ];
    }
}
