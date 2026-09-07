<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\DepositPending;
use App\Services\Webhooks\WebhookDispatcher;

class DispatchDepositPendingWebhook
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handle(DepositPending $event): void
    {
        $this->dispatcher->depositPending($event->deposit);
    }
}
