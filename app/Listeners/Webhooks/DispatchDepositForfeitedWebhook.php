<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\DepositForfeited;
use App\Services\Webhooks\WebhookDispatcher;

class DispatchDepositForfeitedWebhook
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handle(DepositForfeited $event): void
    {
        $this->dispatcher->depositForfeited($event->deposit);
    }
}
