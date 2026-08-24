<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Models\User;
use App\Security\Blocklist\IpBlocklist;
use App\Security\Models\ThreatEvent;

class SuspiciousIpSignal extends FraudSignal
{
    public function key(): string
    {
        return 'suspicious_ip';
    }

    public function label(): string
    {
        return 'Suspicious IP';
    }

    public function defaultWeight(): int
    {
        return 20;
    }

    public function evaluate(User $user): ?string
    {
        $ips = $user->ips()->pluck('ip_address');

        foreach ($ips as $ip) {
            if (IpBlocklist::isBlocked($ip)) {
                return 'Account used a blocked IP address';
            }
        }

        $hasThreats = $ips->isNotEmpty() && ThreatEvent::query()
            ->whereIn('ip_address', $ips)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();

        return $hasThreats ? 'Account IP appears in recent threat events' : null;
    }
}
