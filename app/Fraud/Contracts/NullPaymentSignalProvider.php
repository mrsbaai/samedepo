<?php

declare(strict_types=1);

namespace App\Fraud\Contracts;

use App\Models\User;

class NullPaymentSignalProvider implements PaymentSignalProvider
{
    public function sharedPaymentMethod(User $user): ?string
    {
        return null;
    }

    public function samePaymentEmail(User $user): ?string
    {
        return null;
    }

    public function suspiciousPaymentPattern(User $user): ?string
    {
        return null;
    }
}
