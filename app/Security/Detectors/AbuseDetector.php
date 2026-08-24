<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AbuseDetector extends BaseDetector
{
    public function detect(Request $request, array $inputs): array
    {
        $findings = [];
        $ip = $request->ip();

        // Excessive 404 responses (path scanning). The counter is incremented
        // by the GuardAgainstThreats terminable middleware on 404 responses.
        $notFoundCount = (int) Cache::get(self::notFoundKey($ip), 0);

        if ($notFoundCount > 20) {
            $findings[] = $this->finding('path_scanning', "Excessive 404 responses: {$notFoundCount} in 10 minutes", "IP: {$ip}", 8);
        }

        // Slow-loris style behavior: repeated very slow connections.
        $connectionTime = $request->server('REQUEST_TIME_FLOAT');

        if ($connectionTime && microtime(true) - (float) $connectionTime > 30) {
            $slowKey = "security.slow.{$ip}";
            $slowConnections = (int) Cache::get($slowKey, 0) + 1;
            Cache::put($slowKey, $slowConnections, 300);

            if ($slowConnections > 5) {
                $findings[] = $this->finding('slow_loris', "Slow connection abuse: {$slowConnections} slow connections", "IP: {$ip}", 8);
            }
        }

        return $findings;
    }

    public static function notFoundKey(?string $ip): string
    {
        return 'security.404.'.$ip;
    }
}
