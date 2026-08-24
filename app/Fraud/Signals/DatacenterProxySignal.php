<?php

declare(strict_types=1);

namespace App\Fraud\Signals;

use App\Fraud\Contracts\IpIntelProvider;
use App\Fraud\Contracts\NullIpIntelProvider;
use App\Models\User;

class DatacenterProxySignal extends FraudSignal
{
    public function key(): string
    {
        return 'datacenter_proxy';
    }

    public function label(): string
    {
        return 'Datacenter / proxy';
    }

    public function defaultWeight(): int
    {
        return 20;
    }

    public function available(): bool
    {
        return ! app(IpIntelProvider::class) instanceof NullIpIntelProvider;
    }

    public function evaluate(User $user): ?string
    {
        $provider = app(IpIntelProvider::class);

        foreach ($user->ips()->latest('last_seen_at')->limit(10)->pluck('ip_address') as $ip) {
            if ($provider->isDatacenterOrProxy($ip)) {
                return 'Account used a datacenter or proxy IP';
            }
        }

        return null;
    }
}
