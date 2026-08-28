<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Security\Blocklist\DeviceBlocklist;
use App\Security\Blocklist\IpBlocklist;
use App\Security\Detectors\AbuseDetector;
use App\Security\Models\ThreatEvent;
use App\Security\ThreatDetector;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request-level security layer. Runs as global middleware, before routing,
 * for every web and API request:
 *
 *   1. Reject requests from blocked IPs and devices (plain 403).
 *   2. Run the ThreatDetector; findings at/above the block threshold block
 *      the request and blocklist the IP/device. Lower-severity findings are
 *      recorded and feed the Fraud Engine.
 */
class GuardAgainstThreats
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.enabled')) {
            return $next($request);
        }

        if (Auth::check() && Auth::user()?->is_admin) {
            return $next($request);
        }

        if ($this->isLivewireAdminUpdate($request)) {
            return $next($request);
        }

        $fingerprint = $this->fingerprint($request);

        if ($this->isExemptIp($request)) {
            return $next($request);
        }

        if (rescue(fn (): bool => IpBlocklist::isBlocked($request->ip()) || DeviceBlocklist::isBlocked($fingerprint), false, false)) {
            abort(403);
        }

        foreach ((array) config('security.exempt_paths') as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        $findings = rescue(fn (): array => app(ThreatDetector::class)->inspect($request), [], false);

        if ($findings !== []) {
            $blocked = max(array_column($findings, 'severity')) >= (int) config('security.block_threshold');

            rescue(function () use ($request, $findings, $fingerprint, $blocked): void {
                $this->record($request, $findings, $fingerprint, $blocked);
            }, report: false);

            if ($blocked) {
                abort(403);
            }
        }

        return $next($request);
    }

    /** Count 404 responses per IP so the AbuseDetector can spot path scanning. */
    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() === 404) {
            $key = AbuseDetector::notFoundKey($request->ip());
            Cache::put($key, (int) Cache::get($key, 0) + 1, 600);
        }
    }

    /**
     * @param  array<int, array{detector: string, type: string, description: string, payload: string, severity: int}>  $findings
     */
    private function record(Request $request, array $findings, ?string $fingerprint, bool $blocked): void
    {
        $worst = collect($findings)->sortByDesc('severity')->first();

        ThreatEvent::query()->create([
            'detector' => $worst['detector'],
            'threat_type' => $worst['type'],
            'severity' => $worst['severity'],
            'description' => collect($findings)->pluck('description')->unique()->take(5)->implode(' | '),
            'payload' => $worst['payload'],
            'ip_address' => (string) $request->ip(),
            'fingerprint' => $fingerprint,
            'user_id' => null,
            'method' => $request->method(),
            'path' => substr('/'.$request->path(), 0, 512),
            'blocked' => $blocked,
        ]);

        if ($blocked) {
            $reason = "{$worst['detector']}: {$worst['description']}";
            IpBlocklist::block((string) $request->ip(), $reason, 'threat_detector');

            if ($fingerprint !== null) {
                DeviceBlocklist::block($fingerprint, $reason, 'threat_detector');
            }
        }
    }

    private function fingerprint(Request $request): ?string
    {
        $value = $request->cookies->get((string) config('security.fingerprint_cookie'));

        return is_string($value) && preg_match('/^[a-zA-Z0-9]{8,64}$/', $value) ? $value : null;
    }

    private function isLivewireAdminUpdate(Request $request): bool
    {
        if (! $request->isMethod('POST') || ! str_starts_with($request->path(), 'livewire-')) {
            return false;
        }

        $name = data_get($request->json('components'), '0.name', '');

        return is_string($name) && str_starts_with($name, 'admin.');
    }

    private function isExemptIp(Request $request): bool
    {
        return in_array($request->ip(), (array) config('security.exempt_ips'), true);
    }
}
