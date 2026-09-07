<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Migrations\Migration;

function pendingWebhookMigration(): Migration
{
    return require database_path('migrations/2026_09_08_000100_enable_pending_deposit_webhooks.php');
}

test('it enables pending deposit webhooks for existing endpoints', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create([
        'user_id' => $owner->id,
        'enabled_events' => ['deposit.credited'],
    ]);

    pendingWebhookMigration()->up();

    expect($endpoint->fresh()->enabled_events)->toBe(['deposit.credited', 'deposit.pending']);
});

test('rolling back removes only pending deposit webhooks', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create([
        'user_id' => $owner->id,
        'enabled_events' => ['deposit.credited', 'deposit.pending', 'withdrawal.sent'],
    ]);

    pendingWebhookMigration()->down();

    expect($endpoint->fresh()->enabled_events)->toBe(['deposit.credited', 'withdrawal.sent']);
});
