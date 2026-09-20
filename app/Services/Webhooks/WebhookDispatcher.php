<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Deposit;
use App\Models\UsdValuation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Network;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WebhookDispatcher
{
    public function depositCredited(Deposit $deposit): void
    {
        $endpoint = WebhookEndpoint::query()
            ->withoutGlobalScope('owner')
            ->where('user_id', $deposit->user_id)
            ->first();

        if ($endpoint === null) {
            Log::info('Webhook skipped: no endpoint configured', ['user_id' => $deposit->user_id]);

            return;
        }

        if (! $this->eventEnabled('deposit.credited', $endpoint)) {
            return;
        }

        DeliverWebhook::dispatch($endpoint->id, 'deposit.credited', $this->wrapPayload('deposit.credited', [
            'id' => $deposit->id,
            'customer_id' => $deposit->customer_id,
            'customer_reference' => $deposit->customer?->customer_reference,
            'network' => $deposit->network,
            'tx_hash' => $deposit->tx_hash,
            'gross_amount' => $deposit->gross_amount,
            'gross_amount_usd' => $this->calculateCreditedUsdValue($deposit->network, (string) $deposit->gross_amount),
            'status' => $deposit->status,
            'credited_at' => $deposit->credited_at?->toIso8601String(),
        ]));
    }

    public function depositPending(Deposit $deposit): void
    {
        $endpoint = WebhookEndpoint::query()
            ->withoutGlobalScope('owner')
            ->where('user_id', $deposit->user_id)
            ->first();

        if ($endpoint === null) {
            Log::info('Webhook skipped: no endpoint configured', ['user_id' => $deposit->user_id]);

            return;
        }

        if (! $this->eventEnabled('deposit.pending', $endpoint)) {
            return;
        }

        DeliverWebhook::dispatch($endpoint->id, 'deposit.pending', $this->wrapPayload('deposit.pending', [
            'id' => $deposit->id,
            'customer_id' => $deposit->customer_id,
            'customer_reference' => $deposit->customer?->customer_reference,
            'network' => $deposit->network,
            'tx_hash' => $deposit->tx_hash,
            'gross_amount' => $deposit->gross_amount,
            'gross_amount_usd' => $this->calculateCreditedUsdValue($deposit->network, (string) $deposit->gross_amount),
            'status' => 'pending',
            'confirmation_count' => $deposit->confirmation_count,
            'confirmations_required' => Network::exists($deposit->network) ? Network::confirmations($deposit->network) : 0,
            'detected_at' => $deposit->detected_at?->toIso8601String(),
        ]));
    }

    public function test(WebhookEndpoint $endpoint): bool
    {
        return $this->deliver($endpoint, 'deposit.credited', $this->wrapPayload('deposit.credited', [
            'id' => 0,
            'customer_id' => 0,
            'customer_reference' => 'customer-123',
            'network' => Network::enabledKeys()[0],
            'tx_hash' => 'test-tx',
            'gross_amount' => '0.10000000',
            'gross_amount_usd' => '3000.00',
            'status' => 'credited',
            'credited_at' => now()->toIso8601String(),
            'test' => true,
        ]));
    }

    public function deliver(WebhookEndpoint $endpoint, string $event, array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = Http::withHeaders([
                'X-Samedepo-Event' => $event,
                'X-Samedepo-Signature' => hash_hmac('sha256', $json, $endpoint->secret),
            ])->withBody($json, 'application/json')->post($endpoint->url);

            $this->recordDelivery($endpoint, $event, $payload, $response->successful(), $response->status());

            return $response->successful();
        } catch (Throwable) {
            $this->recordDelivery($endpoint, $event, $payload, false, null);

            return false;
        }
    }

    private function recordDelivery(WebhookEndpoint $endpoint, string $event, array $payload, bool $success, ?int $responseCode): void
    {
        if (! $endpoint->exists) {
            return;
        }

        WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => $event,
            'status' => $success ? WebhookDelivery::STATUS_DELIVERED : WebhookDelivery::STATUS_FAILED,
            'response_code' => $responseCode,
            'payload' => $payload,
        ]);
    }

    private function eventEnabled(string $event, WebhookEndpoint $endpoint): bool
    {
        return in_array($event, (array) $endpoint->enabled_events, true);
    }

    private function calculateCreditedUsdValue(string $network, string $creditedAmount): string
    {
        $valuation = UsdValuation::query()
            ->where('network', $network)
            ->latest('created_at')
            ->first();

        if ($valuation === null) {
            return '0.00';
        }

        $value = bcmul($creditedAmount, (string) $valuation->conversion_value, 8);

        return number_format((float) $value, 2, '.', '');
    }

    private function wrapPayload(string $event, array $data): array
    {
        return [
            'event' => $event,
            'id' => (string) Str::uuid(),
            'created_at' => now()->toIso8601String(),
            'data' => $data,
        ];
    }
}
