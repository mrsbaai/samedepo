<?php

use App\Jobs\DeliverWebhook;
use App\Livewire\Dashboard\Webhooks;
use App\Models\Customer;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function webhookDeliveryPayload(array $data = []): array
{
    return [
        'event' => 'deposit.credited',
        'id' => (string) str()->uuid(),
        'created_at' => now()->toIso8601String(),
        'data' => array_merge([
            'customer_reference' => 'cust-abc',
            'network' => 'bitcoin',
            'tx_hash' => 'txhash-abc',
            'gross_amount' => '0.10000000',
            'gross_amount_usd' => '8594.30',
            'status' => 'credited',
        ], $data),
    ];
}

test('an owner can view the webhooks page with their deliveries', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $owner->id]);
    $otherEndpoint = WebhookEndpoint::factory()->create();

    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'payload' => webhookDeliveryPayload(['customer_reference' => 'cust-mine']),
    ]);
    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $otherEndpoint->id,
        'payload' => webhookDeliveryPayload(['customer_reference' => 'cust-other']),
    ]);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->assertSee('Webhooks', false)
        ->assertSee('cust-mine', false)
        ->assertDontSee('cust-other', false);
});

test('event filter narrows deliveries to a single event', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $owner->id]);

    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'deposit.credited',
        'payload' => webhookDeliveryPayload(['customer_reference' => 'cust-credited']),
    ]);
    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event' => 'deposit.pending',
        'payload' => webhookDeliveryPayload(['event' => 'deposit.pending', 'customer_reference' => 'cust-pending']),
    ]);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->set('eventFilter', 'deposit.pending')
        ->assertSee('cust-pending', false)
        ->assertDontSee('cust-credited', false);
});

test('status filter narrows deliveries to a single status', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $owner->id]);

    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'status' => 'delivered',
        'payload' => webhookDeliveryPayload(['customer_reference' => 'cust-ok']),
    ]);
    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'status' => 'failed',
        'response_code' => 500,
        'payload' => webhookDeliveryPayload(['customer_reference' => 'cust-bad']),
    ]);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->set('statusFilter', 'failed')
        ->assertSee('cust-bad', false)
        ->assertSee('500', false)
        ->assertDontSee('cust-ok', false);
});

test('search narrows deliveries by customer reference or tx hash', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $owner->id]);

    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'payload' => webhookDeliveryPayload(['customer_reference' => 'cust-match', 'tx_hash' => 'match-tx']),
    ]);
    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'payload' => webhookDeliveryPayload(['customer_reference' => 'cust-other', 'tx_hash' => 'other-tx']),
    ]);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->set('search', 'cust-match')
        ->assertSee('cust-match', false)
        ->assertDontSee('cust-other', false);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->set('search', 'other-tx')
        ->assertSee('cust-other', false)
        ->assertDontSee('cust-match', false);
});

test('a customer reference links to the customer detail page', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $owner->id]);
    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'cust-linked']);

    WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'payload' => webhookDeliveryPayload(['customer_reference' => 'cust-linked']),
    ]);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->assertSee(route('customers.show', $customer), false);
});

test('a failed delivery can be retried but a delivered one cannot', function () {
    Queue::fake();
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $owner->id]);
    $failed = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'status' => 'failed',
        'response_code' => 500,
        'payload' => webhookDeliveryPayload(),
    ]);
    $delivered = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'status' => 'delivered',
        'payload' => webhookDeliveryPayload(),
    ]);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->call('redeliver', $delivered->id);

    Queue::assertNotPushed(DeliverWebhook::class);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->call('redeliver', $failed->id);

    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->endpointId === $endpoint->id && $job->event === 'deposit.credited');
});

test('redeliver ignores deliveries belonging to another owner', function () {
    Queue::fake();
    $owner = User::factory()->create(['role' => 'owner']);
    WebhookEndpoint::factory()->create(['user_id' => $owner->id]);
    $otherEndpoint = WebhookEndpoint::factory()->create();
    $foreign = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $otherEndpoint->id,
        'status' => 'failed',
        'payload' => webhookDeliveryPayload(),
    ]);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->call('redeliver', $foreign->id);

    Queue::assertNotPushed(DeliverWebhook::class);
});

test('showPayload opens the modal with the pretty printed payload', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create(['user_id' => $owner->id]);
    $delivery = WebhookDelivery::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'payload' => webhookDeliveryPayload(['tx_hash' => 'payload-tx-hash']),
    ]);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->call('showPayload', $delivery->id)
        ->assertSet('payloadModal', true)
        ->assertSet('payloadId', $delivery->id)
        ->assertSee('payload-tx-hash', false);
});

test('the empty state prompts the owner to configure an endpoint', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->assertSee('No webhook endpoint configured', false)
        ->assertSee('Set up endpoint', false)
        ->assertSee(route('webhook-settings'), false);
});

test('error state renders a callout and retry resets to normal', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(Webhooks::class)
        ->set('uiState', 'error')
        ->assertSee("Couldn't load webhook deliveries")
        ->call('retry')
        ->assertSet('uiState', 'normal')
        ->assertDontSee("Couldn't load webhook deliveries");
});

test('guests are redirected to signin and admins are forbidden', function () {
    $this->get(route('webhooks'))->assertRedirect(route('signin'));

    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('webhooks'))
        ->assertForbidden();
});
