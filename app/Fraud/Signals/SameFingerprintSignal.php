<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Models\User;
use App\Security\Models\SecurityBlock;

class SameFingerprintSignal extends FraudSignal
{
    public function key(): string
    {
        return 'same_fingerprint';
    }

    public function label(): string
    {
        return 'Same fingerprint';
    }

    public function defaultWeight(): int
    {
        return 50;
    }

    public function evaluate(User $user): ?string
    {
        // Blocks created by the Fraud Engine itself are excluded so this
        // signal cannot feed back into the score it produced.
        $blocked = SecurityBlock::query()
            ->where('type', SecurityBlock::TYPE_DEVICE)
            ->where('source', '!=', 'fraud_engine')
            ->whereIn('value', $user->devices()->pluck('fingerprint'))
            ->exists();

        return $blocked ? 'Device fingerprint matches a blocked device' : null;
    }
}
