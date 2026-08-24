<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\ResolvePostSigninRedirect;
use App\Events\Authentication\AuthenticationEvent;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Verify your email'])]
class VerifyEmailNotice extends Component
{
    public string $code = '';

    public string $error = '';

    public string $status = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->hasVerifiedEmail()) {
            $this->redirect(ResolvePostSigninRedirect::for($user), navigate: true);
        }
    }

    public function verify(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $key = 'email-verification|'.mb_strtolower($user->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('authentication.rate_limits.otp_verification'))) {
            $this->error = 'Too many attempts. Please try again later.';

            return;
        }

        $challenge = OtpChallenge::query()
            ->where('email', $user->email)
            ->where('purpose', 'email_verification')
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $challenge instanceof OtpChallenge || $challenge->expires_at->isPast() || $challenge->attempts >= (int) config('authentication.otp.maximum_attempts') || ! Hash::check($this->code, $challenge->code)) {
            RateLimiter::hit($key, 60);

            if ($challenge instanceof OtpChallenge) {
                $challenge->increment('attempts');
            }

            $this->error = 'This code is invalid or has expired.';

            return;
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
        RateLimiter::clear($key);

        $user->markEmailAsVerified();
        $user->forceFill(['is_active' => true])->save();

        event(new Verified($user));

        event(new AuthenticationEvent(
            type: AuthenticationEvent::EMAIL_VERIFIED,
            user: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        ));

        $this->redirect(ResolvePostSigninRedirect::for($user), navigate: true);
    }

    public function resend(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $key = 'verification-resend|'.mb_strtolower($user->email).'|'.request()->ip();
        $decay = (int) config('authentication.email_verification.resend_after_seconds');

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->status = 'Please wait before requesting another code.';

            return;
        }

        RateLimiter::hit($key, $decay);
        $user->sendEmailVerificationNotification();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::EMAIL_VERIFICATION_REQUESTED,
            user: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        ));

        $this->status = 'A new verification code has been sent.';
    }

    public function render(): mixed
    {
        return view('livewire.authentication.verify-email-notice');
    }
}
