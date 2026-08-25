<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Deposit;
use App\Models\WebhookEndpoint;
use App\Models\Withdrawal;
use Illuminate\Support\Str;

class WebhookDispatcher
{
    public function depositCredited(Deposit $deposit): void
    {
        $this->dispatch($deposit->user_id, 'deposit.credited', [
            'id' => $deposit->id,
            'customer_id' => $deposit->customer_id,
            'network' => $deposit->network,
            'tx_hash' => $deposit->tx_hash,
            'gross_amount' => $deposit->gross_amount,
            'fee_amount' => $deposit->fee_amount,
            'credited_amount' => $deposit->credited_amount,
            'status' => $deposit->status,
            'credited_at' => $deposit->credited_at?->toIso8601String(),
        ]);
    }

    public function withdrawalStatus(Withdrawal $withdrawal): void
    {
        $this->dispatch($withdrawal->user_id, 'withdrawal.status', [
            'id' => $withdrawal->id,
            'network' => $withdrawal->network,
            'gross_amount' => $withdrawal->gross_amount,
            'network_fee' => $withdrawal->network_fee,
            'amount_sent' => $withdrawal->amount_sent,
            'destination_address' => $withdrawal->destination_address,
            'mode' => $withdrawal->mode,
            'status' => $withdrawal->status,
            'tx_hash' => $withdrawal->tx_hash,
            'decided_at' => $withdrawal->decided_at?->toIso8601String(),
            'sent_at' => $withdrawal->sent_at?->toIso8601String(),
        ]);
    }

    private function dispatch(int $userId, string $event, array $data): void
    {
        $endpoint = WebhookEndpoint::query()
            ->withoutGlobalScope('owner')
            ->where('user_id', $userId)
            ->first();

        if ($endpoint === null || ! in_array($event, $endpoint->enabled_events ?? [], true)) {
            return;
        }

        DeliverWebhook::dispatch($endpoint->id, $event, [
            'event' => $event,
            'id' => (string) Str::uuid(),
            'created_at' => now()->toIso8601String(),
            'data' => $data,
        ]);
    }
}
