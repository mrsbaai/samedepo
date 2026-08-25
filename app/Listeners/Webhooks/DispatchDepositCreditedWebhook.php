<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\DepositCredited;
use App\Services\Webhooks\WebhookDispatcher;

class DispatchDepositCreditedWebhook
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handle(DepositCredited $event): void
    {
        $this->dispatcher->depositCredited($event->deposit);
    }
}
