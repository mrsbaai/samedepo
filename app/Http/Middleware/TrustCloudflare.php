<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the real client IP when the application is behind Cloudflare.
 *
 * Cloudflare proxies every request, so the direct TCP source address is one of
 * Cloudflare's edge IPs, not the visitor's. Cloudflare sends the original IP in
 * the CF-Connecting-IP header. We only trust that header when the connection
 * actually came from a known Cloudflare IP range, which prevents direct-to-origin
 * requests from spoofing the header.
 */
class TrustCloudflare
{
    /**
     * Cloudflare IPv4 and IPv6 ranges that terminate TLS to the origin.
     *
     * @see https://www.cloudflare.com/ips/
     */
    private const CLOUDFLARE_RANGES = [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.192.0/18',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $realIp = $this->realClientIp($request);

        if ($realIp !== null && $realIp !== $request->server('REMOTE_ADDR')) {
            $request->server->set('REMOTE_ADDR', $realIp);
        }

        return $next($request);
    }

    private function realClientIp(Request $request): ?string
    {
        $forwardedFor = $request->header('CF-Connecting-IP');

        if (! is_string($forwardedFor) || $forwardedFor === '') {
            return null;
        }

        $forwardedFor = trim($forwardedFor);

        if (! filter_var($forwardedFor, FILTER_VALIDATE_IP)) {
            return null;
        }

        $remoteAddr = $request->server('REMOTE_ADDR');

        if (! is_string($remoteAddr) || $remoteAddr === '' || ! $this->isCloudflareIp($remoteAddr)) {
            return null;
        }

        return $forwardedFor;
    }

    private function isCloudflareIp(string $ip): bool
    {
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if (IpUtils::checkIp($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
