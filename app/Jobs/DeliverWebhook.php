<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $endpointId,
        public readonly string $event,
        public readonly array $payload,
    ) {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::query()
            ->withoutGlobalScope('owner')
            ->find($this->endpointId);

        if ($endpoint === null) {
            return;
        }

        $json = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        Http::withHeaders([
            'X-Samedepo-Event' => $this->event,
            'X-Samedepo-Signature' => hash_hmac('sha256', $json, $endpoint->secret),
        ])->withBody($json, 'application/json')->post($endpoint->url)->throw();
    }
}
