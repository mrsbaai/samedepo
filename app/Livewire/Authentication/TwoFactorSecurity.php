<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Two-factor authentication', 'description' => 'Protect your account with an authenticator app.'])]
class TwoFactorSecurity extends Component
{
    public bool $showHeading = true;

    public bool $setupInProgress = false;

    public string $code = '';

    public string $currentPassword = '';

    public string $status = '';

    public string $manualSecret = '';

    public string $qrCodeSvg = '';

    public array $recoveryCodes = [];

    public function beginSetup(EnableTwoFactorAuthentication $enableTwoFactorAuthentication): void
    {
        /** @var User $user */
        $user = auth()->user();
        $enableTwoFactorAuthentication($user);
        $user->refresh();

        $this->manualSecret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
        $this->setupInProgress = true;
    }

    public function cancelSetup(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        /** @var User $user */
        $user = auth()->user();
        $disableTwoFactorAuthentication($user);

        $this->reset('code', 'manualSecret', 'qrCodeSvg');
        $this->setupInProgress = false;
    }

    public function confirmSetup(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $confirmTwoFactorAuthentication($user, $this->code);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return;
        }

        $user->refresh();
        $this->recoveryCodes = $user->recoveryCodes();
        $this->manualSecret = '';
        $this->qrCodeSvg = '';
        $this->setupInProgress = false;
        $this->code = '';
        $this->status = 'Two-factor authentication has been enabled.';

        event(new AuthenticationEvent(
            type: AuthenticationEvent::TWO_FACTOR_ENABLED,
            user: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        ));
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $this->validateOnly('currentPassword', ['currentPassword' => ['required', 'current_password']]);

        /** @var User $user */
        $user = auth()->user();
        $generateNewRecoveryCodes($user);
        $user->refresh();

        $this->recoveryCodes = $user->recoveryCodes();
        $this->currentPassword = '';
        $this->status = 'New recovery codes have been generated.';

        event(new AuthenticationEvent(
            type: AuthenticationEvent::RECOVERY_CODES_REGENERATED,
            user: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        ));
    }

    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->validateOnly('currentPassword', ['currentPassword' => ['required', 'current_password']]);

        /** @var User $user */
        $user = auth()->user();
        $disableTwoFactorAuthentication($user);

        $this->reset('currentPassword', 'recoveryCodes', 'manualSecret', 'qrCodeSvg');
        $this->setupInProgress = false;
        $this->status = 'Two-factor authentication has been disabled.';

        event(new AuthenticationEvent(
            type: AuthenticationEvent::TWO_FACTOR_DISABLED,
            user: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        ));
    }

    public function render(): mixed
    {
        return view('livewire.authentication.two-factor-security');
    }
}
