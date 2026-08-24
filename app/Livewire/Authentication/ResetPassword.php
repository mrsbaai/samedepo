<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\ResetPasswordWithOtp;
use App\Models\OtpChallenge;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Reset your password'])]
class ResetPassword extends Component
{
    public string $password = '';

    public string $passwordConfirmation = '';

    public string $error = '';

    public function resetPassword(ResetPasswordWithOtp $resetPasswordWithOtp): void
    {
        $validated = $this->validate();
        $challengeId = session('password_recovery.challenge_id');
        $email = session('password_recovery.email');
        $challenge = OtpChallenge::query()
            ->whereKey($challengeId)
            ->where('email', $email)
            ->where('purpose', 'password_recovery')
            ->first();

        if (! $challenge instanceof OtpChallenge) {
            $this->error = 'Your recovery session has expired. Request a new code.';

            return;
        }

        $resetPasswordWithOtp->execute($challenge, $validated['password'], request());
        session()->forget(['password_recovery.challenge_id', 'password_recovery.email']);
        $this->redirectRoute('signin', navigate: true);
    }

    protected function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                'confirmed:passwordConfirmation',
                Password::min((int) config('authentication.password.minimum_length'))
                    ->mixedCase()
                    ->numbers()
                    ->uncompromised(),
            ],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.authentication.reset-password');
    }
}
