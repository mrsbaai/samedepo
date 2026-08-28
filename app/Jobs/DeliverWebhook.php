<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use App\Notifications\WebhookEndpointFailing;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $endpoint = WebhookEndpoint::query()
            ->withoutGlobalScope('owner')
            ->find($this->endpointId);

        if ($endpoint === null) {
            return;
        }

        if (! $dispatcher->deliver($endpoint, $this->event, $this->payload)) {
            if ($this->attempts() === 1) {
                $endpoint->user->notify(new WebhookEndpointFailing($endpoint->url));
            }

            throw new \RuntimeException("Webhook delivery failed for event {$this->event}");
        }
    }
}
