<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Models\User;

class MultipleAccountsSameDeviceSignal extends FraudSignal
{
    public function key(): string
    {
        return 'multiple_accounts_same_device';
    }

    public function label(): string
    {
        return 'Multiple accounts on same device';
    }

    public function defaultWeight(): int
    {
        return 40;
    }

    public function evaluate(User $user): ?string
    {
        $sharedAccounts = $user->devices()
            ->withCount('users')
            ->get()
            ->max('users_count');

        if ($sharedAccounts !== null && $sharedAccounts > 1) {
            return "{$sharedAccounts} accounts share the same device";
        }

        return null;
    }
}
