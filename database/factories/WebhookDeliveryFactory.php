<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event' => 'deposit.credited',
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'response_code' => 200,
            'payload' => ['event' => 'deposit.credited', 'id' => (string) fake()->uuid(), 'data' => []],
        ];
    }
}
