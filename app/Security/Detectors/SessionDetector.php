<?php

declare(strict_types=1);

namespace App\Security\Detectors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SessionDetector extends BaseDetector
{
    private const LOGIN_PATHS = ['login', 'signin', 'authenticate', 'auth/login', 'api/login'];

    public function detect(Request $request, array $inputs): array
    {
        $findings = [];
        $ip = $request->ip();

        if ($request->isMethod('POST') && $this->isLoginAttempt($request->path())) {
            $key = "security.logins.{$ip}";
            $attempts = (int) Cache::get($key, 0) + 1;
            Cache::put($key, $attempts, 600);

            if ($attempts > 30) {
                $findings[] = $this->finding('brute_force_login', "Brute force attack: {$attempts} login attempts in 10 minutes", "IP: {$ip}", 8);
            }

            // Same IP + same user agent hammering the login form.
            $uaKey = "security.logins.{$ip}.".md5((string) $request->userAgent());
            $uaAttempts = (int) Cache::get($uaKey, 0) + 1;
            Cache::put($uaKey, $uaAttempts, 300);

            if ($uaAttempts > 20) {
                $findings[] = $this->finding('credential_stuffing', "Credential stuffing: {$uaAttempts} attempts with same user agent", "IP: {$ip}", 8);
            }
        }

        // Malformed session cookie structure.
        $sessionCookie = $request->cookies->get(config('session.cookie'));

        if (is_string($sessionCookie) && preg_match('/[<>"\'`;]/', $sessionCookie)) {
            $findings[] = $this->finding('session_manipulation', 'Suspicious characters in session cookie', $sessionCookie, 7);
        }

        return $findings;
    }

    private function isLoginAttempt(string $path): bool
    {
        foreach (self::LOGIN_PATHS as $loginPath) {
            if (stripos($path, $loginPath) !== false) {
                return true;
            }
        }

        return false;
    }
}
