<?php

declare(strict_types=1);

namespace App\Fraud\Contracts;

use App\Models\User;

/**
 * Extension point for payment-based fraud signals. ForgeOS does not ship a
 * billing module, so the default binding is NullPaymentSignalProvider and the
 * payment metrics stay dormant (score 0). When a billing module is added,
 * bind a real implementation in a service provider:
 *
 *     $this->app->bind(PaymentSignalProvider::class, StripePaymentSignalProvider::class);
 *
 * Each method returns a short human-readable reason when the signal fires,
 * or null when it does not.
 */
interface PaymentSignalProvider
{
    public function sharedPaymentMethod(User $user): ?string;

    public function samePaymentEmail(User $user): ?string;

    public function suspiciousPaymentPattern(User $user): ?string;
}
