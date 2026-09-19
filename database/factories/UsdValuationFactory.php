<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UsdValuation;
use App\Support\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsdValuation>
 */
class UsdValuationFactory extends Factory
{
    protected $model = UsdValuation::class;

    public function definition(): array
    {
        return [
            'network' => fake()->randomElement(Network::keys()),
            'conversion_value' => fake()->randomFloat(6, 0, 100000),
        ];
    }
}
