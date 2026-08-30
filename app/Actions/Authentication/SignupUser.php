<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

class SignupUser
{
    public function __construct(private readonly StatefulGuard $guard) {}

    public function execute(string $email, string $password, Request $request, bool $acceptedTerms = false): User
    {
        $user = User::query()->create([
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
            'is_active' => false,
            'terms_accepted_at' => $acceptedTerms ? now() : null,
        ]);

        $this->guard->login($user);
        session()->regenerate();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::USER_SIGNED_UP,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        $user->sendEmailVerificationNotification();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::EMAIL_VERIFICATION_REQUESTED,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return $user;
    }
}
