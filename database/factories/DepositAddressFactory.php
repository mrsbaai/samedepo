<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\DepositAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepositAddress>
 */
class DepositAddressFactory extends Factory
{
    protected $model = DepositAddress::class;

    public function definition(): array
    {
        $network = fake()->randomElement(['bitcoin', 'usdt_trc20', 'usdt_erc20']);

        return [
            'customer_id' => Customer::factory(),
            'network' => $network,
            'address' => fake()->uuid().'-'.$network,
        ];
    }
}
