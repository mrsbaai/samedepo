<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Deposit;
use App\Models\WebhookEndpoint;
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

        DeliverWebhook::dispatch($endpoint->id, 'deposit.credited', $this->wrapPayload('deposit.credited', [
            'id' => $deposit->id,
            'customer_id' => $deposit->customer_id,
            'network' => $deposit->network,
            'tx_hash' => $deposit->tx_hash,
            'gross_amount' => $deposit->gross_amount,
            'fee_amount' => $deposit->fee_amount,
            'credited_amount' => $deposit->credited_amount,
            'status' => $deposit->status,
            'credited_at' => $deposit->credited_at?->toIso8601String(),
        ]));
    }

    public function test(WebhookEndpoint $endpoint): bool
    {
        return $this->deliver($endpoint, 'deposit.credited', $this->wrapPayload('deposit.credited', [
            'id' => 0,
            'customer_id' => 0,
            'network' => 'bitcoin',
            'tx_hash' => 'test-tx',
            'gross_amount' => '0.10000000',
            'fee_amount' => '0.00050000',
            'credited_amount' => '0.09950000',
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

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
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
