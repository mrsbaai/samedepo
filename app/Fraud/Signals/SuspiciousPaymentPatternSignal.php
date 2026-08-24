<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Fraud\Contracts\NullPaymentSignalProvider;
use App\Fraud\Contracts\PaymentSignalProvider;
use App\Models\User;

class SuspiciousPaymentPatternSignal extends FraudSignal
{
    public function key(): string
    {
        return 'suspicious_payment_pattern';
    }

    public function label(): string
    {
        return 'Suspicious payment pattern';
    }

    public function defaultWeight(): int
    {
        return 50;
    }

    public function available(): bool
    {
        return ! app(PaymentSignalProvider::class) instanceof NullPaymentSignalProvider;
    }

    public function evaluate(User $user): ?string
    {
        return app(PaymentSignalProvider::class)->suspiciousPaymentPattern($user);
    }
}
