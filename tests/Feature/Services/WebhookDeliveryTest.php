<?php

declare(strict_types=1);

use App\Events\DepositCredited;
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
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
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

test('deposit credited dispatches a queued webhook with the expected payload', function () {
    Queue::fake();
    [$owner] = webhookOwner(['deposit.credited']);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => 30000.00]);
    $deposit = creditedDeposit($owner);

    DepositCredited::dispatch($deposit);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use ($deposit) {
        return $job->event === 'deposit.credited'
            && $job->payload['data']['id'] === $deposit->id
            && $job->payload['data']['network'] === 'bitcoin'
            && $job->payload['data']['credited_amount'] === '1.23750000'
            && $job->payload['data']['credited_usd_value'] === '37125.00';
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

test('deposit credited payload uses the latest stored usd valuation for the network', function () {
    Queue::fake();
    [$owner] = webhookOwner(['deposit.credited']);
    $valuation = UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => 10000.00]);
    $valuation->update(['conversion_value' => 30000.00]);
    $deposit = creditedDeposit($owner);

    DepositCredited::dispatch($deposit);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) {
        return $job->payload['data']['credited_usd_value'] === '37125.00';
    });
});

test('deposit credited payload values are based on credited amount not gross or fee', function () {
    Queue::fake();
    [$owner] = webhookOwner(['deposit.credited']);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => 30000.00]);
    $deposit = creditedDeposit($owner);

    DepositCredited::dispatch($deposit);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) {
        $credited = $job->payload['data']['credited_amount'];
        $gross = $job->payload['data']['gross_amount'];
        $usd = $job->payload['data']['credited_usd_value'];

        return $credited === '1.23750000'
            && $gross === '1.25000000'
            && $usd === '37125.00';
    });
});

test('usdt deposit credited payload includes two decimal usd value', function () {
    Queue::fake();
    [$owner] = webhookOwner(['deposit.credited']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
    ]);
    $deposit = Deposit::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'deposit_address_id' => $address->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '150.00000000',
        'fee_amount' => '1.50000000',
        'credited_amount' => '148.50000000',
        'status' => 'credited',
        'tx_hash' => 'deposit-tx',
        'credited_at' => now(),
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => 1.00]);

    DepositCredited::dispatch($deposit);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) {
        return $job->payload['data']['network'] === 'usdt_trc20'
            && $job->payload['data']['credited_amount'] === '148.50000000'
            && $job->payload['data']['credited_usd_value'] === '148.50';
    });
});

test('webhook signature covers the credited_usd_value field', function () {
    Http::fake(['https://example.test/webhooks' => Http::response(status: 204)]);
    [$owner, $endpoint] = webhookOwner(['deposit.credited']);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => 30000.00]);
    $deposit = creditedDeposit($owner);

    $dispatcher = app(WebhookDispatcher::class);
    $dispatcher->depositCredited($deposit);

    $payload = [
        'event' => 'deposit.credited',
        'id' => 'test-event-id',
        'created_at' => '2026-08-25T12:00:00Z',
        'data' => [
            'id' => $deposit->id,
            'customer_id' => $deposit->customer_id,
            'network' => 'bitcoin',
            'tx_hash' => 'deposit-tx',
            'gross_amount' => '1.25000000',
            'fee_amount' => '0.01250000',
            'credited_amount' => '1.23750000',
            'credited_usd_value' => '37125.00',
            'status' => 'credited',
            'credited_at' => $deposit->credited_at?->toIso8601String(),
        ],
    ];

    (new DeliverWebhook($endpoint->id, 'deposit.credited', $payload))->handle($dispatcher);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    Http::assertSent(fn ($request) => $request->url() === $endpoint->url
        && $request->body() === $json
        && $request->hasHeader('X-Samedepo-Signature', hash_hmac('sha256', $json, 'webhook-secret')));
});

test('test webhook payload includes a representative credited_usd_value', function () {
    Http::fake(['https://example.test/webhooks' => Http::response(status: 204)]);
    [$owner, $endpoint] = webhookOwner(['deposit.credited']);

    (new WebhookDispatcher)->test($endpoint);

    Http::assertSent(fn ($request) => str_contains($request->body(), '"credited_usd_value":"2985.00"'));
});
