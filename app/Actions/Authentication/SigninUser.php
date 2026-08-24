<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class SigninUser
{
    public function __construct(private readonly StatefulGuard $guard) {}

    public function execute(string $email, string $password, bool $remember, Request $request): bool
    {
        $email = mb_strtolower(trim($email));
        $key = 'signin|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('authentication.rate_limits.signin'))) {
            return false;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! Hash::check($password, $user->password) || ! $user->is_active || ! $user->hasVerifiedEmail() || $user->hasRequestedDeletion()) {
            RateLimiter::hit($key, 60);

            event(new AuthenticationEvent(
                type: AuthenticationEvent::SIGNIN_FAILED,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            ));

            return false;
        }

        RateLimiter::clear($key);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            session([
                'signin.id' => $user->id,
                'signin.remember' => $remember,
            ]);

            return true;
        }

        $this->guard->login($user, $remember);
        session()->regenerate();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::USER_SIGNED_IN,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return true;
    }
}
