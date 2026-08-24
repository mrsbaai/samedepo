<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Models\User;
use App\Security\Models\ThreatEvent;

class ThreatDetectorEventSignal extends FraudSignal
{
    public function key(): string
    {
        return 'threat_detector_event';
    }

    public function label(): string
    {
        return 'ThreatDetector event';
    }

    public function defaultWeight(): int
    {
        return 50;
    }

    public function evaluate(User $user): ?string
    {
        $fingerprints = $user->devices()->pluck('fingerprint');

        $count = ThreatEvent::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->where(function ($query) use ($user, $fingerprints): void {
                $query->where('user_id', $user->id);

                if ($fingerprints->isNotEmpty()) {
                    $query->orWhereIn('fingerprint', $fingerprints);
                }
            })
            ->count();

        return $count > 0 ? "{$count} threat events linked to this account's devices" : null;
    }
}
