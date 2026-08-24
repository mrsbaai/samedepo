<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Fraud\FraudEngine;
use App\Fraud\Models\Device;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Associates the FingerprintJS device cookie and request IP with the
 * authenticated user, enforces the fraud user status, and triggers a
 * throttled fraud re-evaluation.
 */
class IdentifyDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->fraud_status === 'blocked' && ! $user->is_admin) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            abort(403);
        }

        rescue(fn () => $this->track($request, $user), report: false);

        return $next($request);
    }

    private function track(Request $request, User $user): void
    {
        $fingerprint = $this->fingerprint($request);
        $fresh = false;

        if ($fingerprint !== null) {
            $device = Device::query()->firstOrCreate(
                ['fingerprint' => $fingerprint],
                ['user_agent' => $request->userAgent(), 'first_seen_at' => now()]
            );
            $device->forceFill(['last_seen_at' => now()])->save();

            $fresh = ! $device->users()->whereKey($user->id)->exists();

            if ($fresh) {
                $device->users()->attach($user->id);
            }
        }

        if ($request->ip() !== null) {
            $ip = $user->ips()->firstOrCreate(['ip_address' => $request->ip()]);
            $fresh = $fresh || $ip->wasRecentlyCreated;
            $ip->forceFill(['last_seen_at' => now()])->save();
        }

        $throttleKey = "fraud.evaluated.{$user->id}";

        if ($fresh || ! Cache::has($throttleKey)) {
            Cache::put($throttleKey, true, now()->addMinutes((int) config('security.fraud.evaluation_throttle_minutes')));
            app(FraudEngine::class)->evaluate($user);
        }
    }

    private function fingerprint(Request $request): ?string
    {
        $value = $request->cookies->get((string) config('security.fingerprint_cookie'));

        return is_string($value) && preg_match('/^[a-zA-Z0-9]{8,64}$/', $value) ? $value : null;
    }
}
