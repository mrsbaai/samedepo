<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\ResolvePostSigninRedirect;
use App\Events\Authentication\AuthenticationEvent;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Verify your identity', 'description' => 'Enter a code from your authenticator app or use a recovery code.'])]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public string $recoveryCode = '';

    public string $error = '';

    public bool $showRecoveryCode = false;

    public function showRecoveryCodeForm(): void
    {
        $this->showRecoveryCode = true;
    }

    public function hideRecoveryCodeForm(): void
    {
        $this->showRecoveryCode = false;
    }

    public function verify(TwoFactorAuthenticationProvider $provider, StatefulGuard $guard): void
    {
        $user = $this->challengedUser();

        if (! $user instanceof User || ! $provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $this->code)) {
            $this->error = 'The provided two-factor code is invalid.';

            return;
        }

        $this->completeSignin($user, $guard);
    }

    public function verifyRecoveryCode(StatefulGuard $guard): void
    {
        $user = $this->challengedUser();

        if (! $user instanceof User || ! in_array($this->recoveryCode, $user->recoveryCodes(), true)) {
            $this->error = 'The provided recovery code is invalid.';

            return;
        }

        $user->replaceRecoveryCode($this->recoveryCode);
        $this->completeSignin($user, $guard);
    }

    private function challengedUser(): ?User
    {
        $user = User::query()->find(session('signin.id'));

        return $user instanceof User && $user->hasEnabledTwoFactorAuthentication() ? $user : null;
    }

    private function completeSignin(User $user, StatefulGuard $guard): void
    {
        $guard->login($user, (bool) session()->pull('signin.remember', false));
        session()->forget('signin.id');
        session()->regenerate();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::USER_SIGNED_IN,
            user: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        ));

        $this->redirect(ResolvePostSigninRedirect::for($user), navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.authentication.two-factor-challenge');
    }
}
