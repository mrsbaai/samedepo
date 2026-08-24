<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\IssuePasswordRecoveryCode;
use App\Models\OtpChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Verify recovery code', 'description' => 'Enter the six-digit code sent to your email.'])]
class VerifyOtp extends Component
{
    public string $email = '';

    public string $code = '';

    public string $error = '';

    public string $status = '';

    public function mount(): void
    {
        $this->email = mb_strtolower(trim((string) request()->query('email', '')));
    }

    public function resend(IssuePasswordRecoveryCode $issuePasswordRecoveryCode): void
    {
        $key = 'otp-resend|'.$this->email.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('authentication.otp.maximum_resends'))) {
            $this->status = 'Please wait before requesting another recovery code.';

            return;
        }

        RateLimiter::hit($key, (int) config('authentication.otp.resend_after_seconds'));
        $issuePasswordRecoveryCode->execute($this->email, request());
        $this->status = 'If an account exists, we sent a new recovery code.';
    }

    public function verify(): void
    {
        $key = 'otp-verification|'.$this->email.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('authentication.rate_limits.otp_verification'))) {
            $this->error = 'Too many verification attempts. Please try again later.';

            return;
        }

        $challenge = OtpChallenge::query()
            ->where('email', $this->email)
            ->where('purpose', 'password_recovery')
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
        session([
            'password_recovery.challenge_id' => $challenge->id,
            'password_recovery.email' => $this->email,
        ]);

        $this->redirectRoute('password.reset', navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.authentication.verify-otp');
    }
}
