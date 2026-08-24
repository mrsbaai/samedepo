<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\IssuePasswordRecoveryCode;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Forgot your password?'])]
class ForgotPassword extends Component
{
    public string $email = '';

    public string $status = '';

    public function sendCode(IssuePasswordRecoveryCode $issuePasswordRecoveryCode): void
    {
        $this->email = mb_strtolower(trim($this->email));
        $this->validate();

        $key = 'password-recovery|'.$this->email.'|'.request()->ip();

        if (! RateLimiter::tooManyAttempts($key, (int) config('authentication.rate_limits.password_recovery'))) {
            RateLimiter::hit($key, 60);
            $issuePasswordRecoveryCode->execute($this->email, request());
        }

        $this->status = 'If an account exists, we sent a recovery code.';
    }

    protected function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255']];
    }

    public function render(): mixed
    {
        return view('livewire.authentication.forgot-password');
    }
}
