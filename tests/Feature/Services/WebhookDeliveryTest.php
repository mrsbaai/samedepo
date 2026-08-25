<?php

declare(strict_types=1);

use App\Events\DepositCredited;
use App\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Withdrawal;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function webhookOwner(array $enabledEvents): array
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
    $deposit = creditedDeposit($owner);

    DepositCredited::dispatch($deposit);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use ($deposit) {
        return $job->event === 'deposit.credited'
            && $job->payload['data']['id'] === $deposit->id
            && $job->payload['data']['network'] === 'bitcoin'
            && $job->payload['data']['credited_amount'] === '1.23750000';
    });
});

test('withdrawal status changes dispatch queued webhooks for every status', function () {
    Queue::fake();
    [$owner] = webhookOwner(['withdrawal.status']);

    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'mode' => 'approval',
        'status' => 'pending',
    ]);

    foreach (['approved', 'denied', 'cancelled', 'sent'] as $status) {
        $withdrawal->update(['status' => $status]);
    }

    foreach (['pending', 'approved', 'denied', 'cancelled', 'sent'] as $status) {
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->event === 'withdrawal.status'
            && $job->payload['data']['id'] === $withdrawal->id
            && $job->payload['data']['status'] === $status);
    }
});

test('disabled events and missing endpoints do not dispatch webhooks', function () {
    Queue::fake();
    [$owner] = webhookOwner([]);

    DepositCredited::dispatch(creditedDeposit($owner));
    Withdrawal::factory()->create(['user_id' => $owner->id, 'status' => 'pending']);

    $otherOwner = User::factory()->create(['role' => 'owner']);
    DepositCredited::dispatch(creditedDeposit($otherOwner));

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

    (new DeliverWebhook($endpoint->id, 'deposit.credited', $payload))->handle();

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    Http::assertSent(fn ($request) => $request->url() === $endpoint->url
        && $request->body() === $json
        && $request->hasHeader('X-Samedepo-Event', 'deposit.credited')
        && $request->hasHeader('X-Samedepo-Signature', hash_hmac('sha256', $json, 'webhook-secret')));
});

test('failed deliveries throw for queue retry with bounded backoff', function () {
    Http::fake(['https://example.test/webhooks' => Http::response(status: 500)]);
    [, $endpoint] = webhookOwner(['deposit.credited']);
    $job = new DeliverWebhook($endpoint->id, 'deposit.credited', ['event' => 'deposit.credited']);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900]);

    expect(fn () => $job->handle())->toThrow(RequestException::class);
});
