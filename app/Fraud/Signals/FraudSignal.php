<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Models\User;

/**
 * One scoring metric of the Fraud Engine. Each signal has exactly one
 * responsibility: decide whether it fires for a user and say why.
 * Enabled state and weight are admin-configurable (fraud_metric_settings).
 */
abstract class FraudSignal
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function defaultWeight(): int;

    /** Whether this signal has a live data source in this install. */
    public function available(): bool
    {
        return true;
    }

    /** Return a short reason when the signal fires, or null when it doesn't. */
    abstract public function evaluate(User $user): ?string;
}
