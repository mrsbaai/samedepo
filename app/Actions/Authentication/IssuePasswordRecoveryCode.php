<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Notifications\Authentication\PasswordRecoveryCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class IssuePasswordRecoveryCode
{
    public function execute(string $email, Request $request): void
    {
        $email = mb_strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            return;
        }

        OtpChallenge::query()
            ->where('email', $email)
            ->where('purpose', 'password_recovery')
            ->whereNull('consumed_at')
            ->delete();

        $code = (string) random_int(100000, 999999);

        OtpChallenge::query()->create([
            'user_id' => $user->id,
            'email' => $email,
            'purpose' => 'password_recovery',
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('authentication.otp.expires_after_minutes')),
            'request_ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        $user->notify(new PasswordRecoveryCodeNotification($code));

        event(new AuthenticationEvent(
            type: AuthenticationEvent::PASSWORD_RESET_REQUESTED,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }
}
