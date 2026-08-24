<?php

declare(strict_types=1);

namespace App\Fraud\Contracts;

class NullIpIntelProvider implements IpIntelProvider
{
    public function isDatacenterOrProxy(string $ip): bool
    {
        return false;
    }
}
