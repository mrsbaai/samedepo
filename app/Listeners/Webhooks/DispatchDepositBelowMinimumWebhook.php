<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Events\DepositBelowMinimum;
use App\Services\Webhooks\WebhookDispatcher;

class DispatchDepositBelowMinimumWebhook
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function handle(DepositBelowMinimum $event): void
    {
        $this->dispatcher->depositBelowMinimum($event->deposit);
    }
}
