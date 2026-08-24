<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PublicContentPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicContentPage>
 */
class PublicContentPageFactory extends Factory
{
    protected $model = PublicContentPage::class;

    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['terms', 'privacy']),
            'content' => fake()->paragraph(),
        ];
    }
}
