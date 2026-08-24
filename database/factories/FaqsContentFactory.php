<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FaqsContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FaqsContent>
 */
class FaqsContentFactory extends Factory
{
    protected $model = FaqsContent::class;

    public function definition(): array
    {
        return [
            'content' => fake()->paragraph(),
        ];
    }
}
