<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Fraud\Contracts\NullPaymentSignalProvider;
use App\Fraud\Contracts\PaymentSignalProvider;
use App\Models\User;

class SamePaymentEmailSignal extends FraudSignal
{
    public function key(): string
    {
        return 'same_payment_email';
    }

    public function label(): string
    {
        return 'Same payment email';
    }

    public function defaultWeight(): int
    {
        return 40;
    }

    public function available(): bool
    {
        return ! app(PaymentSignalProvider::class) instanceof NullPaymentSignalProvider;
    }

    public function evaluate(User $user): ?string
    {
        return app(PaymentSignalProvider::class)->samePaymentEmail($user);
    }
}
