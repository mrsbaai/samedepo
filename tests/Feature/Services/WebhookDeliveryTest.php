<?php

declare(strict_types=1);

use App\Events\DepositCredited;
use App\Events\DepositPending;
use App\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\WebhookEndpointFailing;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function webhookOwner(array $enabledEvents = ['deposit.credited']): array
{
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create([
        'user_id' => $owner->id,
        'url' => 'https://example.test/webhooks',
        'enabled_events' => $enabledEvents,
        'secret' => 'webhook-secret',
    ]);

    return [$owner, $endpoint];
}

function creditedDeposit(User $owner): Deposit
{
    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'customer-123']);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
    ]);

    return Deposit::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'deposit_address_id' => $address->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.25000000',
        'fee_amount' => '0.01250000',
        'credited_amount' => '1.23750000',
        'status' => 'credited',
        'tx_hash' => 'deposit-tx',
        'credited_at' => now(),
    ]);
}

function pendingDeposit(User $owner, int $confirmations = 2): Deposit
{
    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'customer-123']);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
    ]);

    return Deposit::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'deposit_address_id' => $address->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.25000000',
        'status' => 'pending',
        'confirmation_count' => $confirmations,
        'tx_hash' => 'pending-tx',
        'detected_at' => now(),
    ]);
}

test('deposit credited dispatches a queued webhook with the expected payload', function () {
    Queue::fake();
    [$owner] = webhookOwner(['deposit.credited']);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => 30000.00]);
    $deposit = creditedDeposit($owner);

    DepositCredited::dispatch($deposit);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use ($deposit) {
        return $job->event === 'deposit.credited'
            && $job->payload['data']['id'] === $deposit->id
            && $job->payload['data']['customer_reference'] === 'customer-123'
            && $job->payload['data']['network'] === 'bitcoin'
            && $job->payload['data']['gross_amount_usd'] === '37500.00'
            && ! isset($job->payload['data']['fee_amount'])
            && ! isset($job->payload['data']['credited_amount'])
            && ! isset($job->payload['data']['credited_usd_value']);
    });
});

test('missing endpoint does not dispatch a webhook', function () {
    Queue::fake();
    $owner = User::factory()->create(['role' => 'owner']);

    DepositCredited::dispatch(creditedDeposit($owner));

    Queue::assertNothingPushed();
});

test('delivery signs and posts the exact json payload', function () {
    Http::fake(['https://example.test/webhooks' => Http::response(status: 204)]);
    [, $endpoint] = webhookOwner(['deposit.credited']);
    $payload = [
        'event' => 'deposit.credited',
        'id' => 'event-id',
        'created_at' => '2026-08-25T12:00:00Z',
        'data' => ['id' => 10],
    ];

    (new DeliverWebhook($endpoint->id, 'deposit.credited', $payload))->handle(app(WebhookDispatcher::class));

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    Http::assertSent(fn ($request) => $request->url() === $endpoint->url
        && $request->body() === $json
        && $request->hasHeader('X-Samedepo-Event', 'deposit.credited')
        && $request->hasHeader('X-Samedepo-Signature', hash_hmac('sha256', $json, 'webhook-secret')));
});

test('failed deliveries throw for queue retry with bounded backoff and notify on the first attempt', function () {
    Http::fake(['https://example.test/webhooks' => Http::response(status: 500)]);
    Notification::fake();
    [$owner, $endpoint] = webhookOwner(['deposit.credited']);
    $job = new DeliverWebhook($endpoint->id, 'deposit.credited', ['event' => 'deposit.credited']);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900]);

    expect(fn () => $job->handle(app(WebhookDispatcher::class)))->toThrow(RuntimeException::class);

    Notification::assertSentTo($owner, WebhookEndpointFailing::class);
});

test('test webhook uses the public deposit payload fields', function () {
    Http::fake(['https://example.test/webhooks' => Http::response(status: 204)]);
    [, $endpoint] = webhookOwner(['deposit.credited']);

    (new WebhookDispatcher)->test($endpoint);

    Http::assertSent(function ($request) {
        $data = $request->data()['data'];

        return $data['gross_amount_usd'] === '3000.00'
            && ! isset($data['fee_amount'])
            && ! isset($data['credited_amount'])
            && ! isset($data['credited_usd_value']);
    });
});

test('deposit pending dispatches a queued webhook with the expected payload', function () {
    Queue::fake();
    [$owner] = webhookOwner(['deposit.pending']);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => 30000.00]);
    $deposit = pendingDeposit($owner, 2);

    DepositPending::dispatch($deposit);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use ($deposit) {
        return $job->event === 'deposit.pending'
            && $job->payload['data']['id'] === $deposit->id
            && $job->payload['data']['customer_id'] === $deposit->customer_id
            && $job->payload['data']['customer_reference'] === 'customer-123'
            && $job->payload['data']['network'] === 'bitcoin'
            && $job->payload['data']['tx_hash'] === 'pending-tx'
            && $job->payload['data']['gross_amount'] === '1.25000000'
            && $job->payload['data']['gross_amount_usd'] === '37500.00'
            && $job->payload['data']['status'] === 'pending'
            && $job->payload['data']['confirmation_count'] === 2
            && $job->payload['data']['confirmations_required'] === 3
            && $job->payload['data']['detected_at'] !== null
            && ! isset($job->payload['data']['credited_at'])
            && ! isset($job->payload['data']['fee_amount'])
            && ! isset($job->payload['data']['credited_amount']);
    });
});

test('deposit pending is not dispatched when the endpoint does not enable it', function () {
    Queue::fake();
    [$owner] = webhookOwner(['deposit.credited']);
    $deposit = pendingDeposit($owner);

    DepositPending::dispatch($deposit);

    Queue::assertNothingPushed();
});
