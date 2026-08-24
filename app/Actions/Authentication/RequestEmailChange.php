<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\Authentication\EmailChangeVerificationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class RequestEmailChange
{
    public function execute(User $user, string $newEmail, Request $request): EmailChangeRequest
    {
        $user->emailChangeRequests()
            ->whereNull('verified_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->update(['cancelled_at' => now()]);

        $token = Str::random(64);
        $expiresAt = now()->addMinutes((int) config('authentication.email_change.expires_after_minutes'));

        /** @var EmailChangeRequest $changeRequest */
        $changeRequest = $user->emailChangeRequests()->create([
            'pending_email' => $newEmail,
            'verification_token' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        $verificationUrl = route('email.verify-change', [
            'id' => $changeRequest->id,
            'token' => $token,
        ]);

        Notification::route('mail', $newEmail)
            ->notify(new EmailChangeVerificationNotification($verificationUrl));

        event(new AuthenticationEvent(
            type: AuthenticationEvent::EMAIL_CHANGE_REQUESTED,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return $changeRequest;
    }
}
