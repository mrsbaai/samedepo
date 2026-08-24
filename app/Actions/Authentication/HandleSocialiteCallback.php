<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\AuthenticationIdentity;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class HandleSocialiteCallback
{
    public function __construct(private readonly StatefulGuard $guard) {}

    public function execute(string $provider, SocialiteUser $socialUser, Request $request): array
    {
        $providerId = (string) $socialUser->getId();
        $email = $socialUser->getEmail();

        if ($providerId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->dispatchFailure($provider, $request);

            return $this->failure('missing_email', 'We could not retrieve a valid email address from this provider.');
        }

        $email = mb_strtolower(trim($email));
        $hash = hash('sha256', $providerId);
        $authenticatedUser = auth()->user();

        $identity = AuthenticationIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_hash', $hash)
            ->first();

        if ($authenticatedUser instanceof User) {
            return $this->handleLink($provider, $socialUser, $authenticatedUser, $identity, $request);
        }

        return $this->handleSignin($provider, $socialUser, $identity, $request);
    }

    private function handleLink(string $provider, SocialiteUser $socialUser, User $user, ?AuthenticationIdentity $identity, Request $request): array
    {
        $providerId = (string) $socialUser->getId();

        if ($identity !== null && $identity->user_id !== $user->id) {
            return $this->failure('conflict', 'This '.$provider.' account is already linked to another account.');
        }

        if ($identity !== null) {
            return ['status' => 'linked', 'user' => $user];
        }

        $user->authenticationIdentities()->create([
            'provider' => $provider,
            'provider_user_hash' => hash('sha256', $providerId),
            'provider_user_id' => $providerId,
            'access_token' => $socialUser->token ?? null,
            'token_expires_at' => $this->tokenExpiresAt($socialUser),
        ]);

        event(new AuthenticationEvent(
            type: AuthenticationEvent::SOCIAL_PROVIDER_LINKED,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            metadata: ['provider' => $provider],
        ));

        return ['status' => 'linked', 'user' => $user];
    }

    private function handleSignin(string $provider, SocialiteUser $socialUser, ?AuthenticationIdentity $identity, Request $request): array
    {
        $providerId = (string) $socialUser->getId();
        $email = mb_strtolower(trim((string) $socialUser->getEmail()));

        if ($identity !== null) {
            $user = $identity->user;

            if (! $user instanceof User || ! $user->is_active || $user->hasRequestedDeletion()) {
                $this->dispatchFailure($provider, $request);

                return $this->failure('conflict', 'Unable to sign in with this provider.');
            }

            return $this->authenticate($user, $request);
        }

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser instanceof User) {
            $this->dispatchFailure($provider, $request);

            return $this->failure('existing_email', 'An account with this email already exists. Sign in first to link your '.ucfirst($provider).' account.');
        }

        $user = User::query()->create([
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'is_active' => true,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $user->authenticationIdentities()->create([
            'provider' => $provider,
            'provider_user_hash' => hash('sha256', $providerId),
            'provider_user_id' => $providerId,
            'access_token' => $socialUser->token ?? null,
            'token_expires_at' => $this->tokenExpiresAt($socialUser),
        ]);

        event(new AuthenticationEvent(
            type: AuthenticationEvent::USER_SIGNED_UP,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return $this->authenticate($user, $request);
    }

    private function authenticate(User $user, Request $request): array
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            session([
                'signin.id' => $user->id,
                'signin.remember' => false,
            ]);

            return ['status' => 'two_factor_required'];
        }

        $this->guard->login($user);
        session()->regenerate();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::USER_SIGNED_IN,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return ['status' => 'signed_in', 'user' => $user];
    }

    private function tokenExpiresAt(SocialiteUser $socialUser): ?Carbon
    {
        if (! property_exists($socialUser, 'expiresIn') || ! is_int($socialUser->expiresIn)) {
            return null;
        }

        return now()->addSeconds($socialUser->expiresIn);
    }

    private function dispatchFailure(string $provider, Request $request): void
    {
        event(new AuthenticationEvent(
            type: AuthenticationEvent::SIGNIN_FAILED,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            metadata: ['provider' => $provider],
        ));
    }

    private function failure(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }
}
