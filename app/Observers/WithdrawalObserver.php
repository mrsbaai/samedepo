<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Withdrawal;
use App\Services\Webhooks\WebhookDispatcher;

class WithdrawalObserver
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function created(Withdrawal $withdrawal): void
    {
        $this->dispatcher->withdrawalStatus($withdrawal);
    }

    public function updated(Withdrawal $withdrawal): void
    {
        if ($withdrawal->wasChanged('status')) {
            $this->dispatcher->withdrawalStatus($withdrawal);
        }
    }
}
