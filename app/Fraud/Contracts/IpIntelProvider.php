<?php

declare(strict_types=1);

namespace App\Fraud\Contracts;

/**
 * Extension point for IP intelligence (datacenter/proxy/VPN detection).
 * The default binding is NullIpIntelProvider, which keeps the
 * datacenter_proxy metric dormant. Bind a real implementation (e.g. an
 * IPinfo or MaxMind lookup) to activate it.
 */
interface IpIntelProvider
{
    public function isDatacenterOrProxy(string $ip): bool;
}
