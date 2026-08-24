<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Fraud\Models\EntityLink;
use App\Models\User;

class PreviousFraudConnectionSignal extends FraudSignal
{
    public function key(): string
    {
        return 'previous_fraud_connection';
    }

    public function label(): string
    {
        return 'Previous fraud connection';
    }

    public function defaultWeight(): int
    {
        return 80;
    }

    public function evaluate(User $user): ?string
    {
        $connected = EntityLink::query()
            ->where('user_id', $user->id)
            ->whereHas('linkedUser', fn ($query) => $query->where('fraud_status', 'blocked'))
            ->exists();

        return $connected ? 'Linked to an account blocked for fraud' : null;
    }
}
