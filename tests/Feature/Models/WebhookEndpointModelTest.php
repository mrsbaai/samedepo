<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WebhookEndpoint;

test('a webhook endpoint belongs to an owner', function () {
    $endpoint = WebhookEndpoint::factory()->create();

    expect($endpoint->user)->toBeInstanceOf(User::class);
});

test('webhook endpoints are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    WebhookEndpoint::create([
        'user_id' => $owner->id,
        'url' => 'https://owner.test/webhook',
        'enabled_events' => ['deposit.credited'],
        'secret' => 'owner-secret',
    ]);
    WebhookEndpoint::create([
        'user_id' => $otherOwner->id,
        'url' => 'https://other.test/webhook',
        'enabled_events' => ['deposit.credited'],
        'secret' => 'other-secret',
    ]);

    $this->actingAs($owner);

    expect(WebhookEndpoint::pluck('url')->toArray())->toBe(['https://owner.test/webhook']);
});
