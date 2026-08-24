<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Fraud\Contracts\NullPaymentSignalProvider;
use App\Fraud\Contracts\PaymentSignalProvider;
use App\Models\User;

class SharedPaymentMethodSignal extends FraudSignal
{
    public function key(): string
    {
        return 'shared_payment_method';
    }

    public function label(): string
    {
        return 'Shared payment method';
    }

    public function defaultWeight(): int
    {
        return 80;
    }

    public function available(): bool
    {
        return ! app(PaymentSignalProvider::class) instanceof NullPaymentSignalProvider;
    }

    public function evaluate(User $user): ?string
    {
        return app(PaymentSignalProvider::class)->sharedPaymentMethod($user);
    }
}
