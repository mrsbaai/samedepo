<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\RequestEmailChange;
use App\Events\Authentication\AuthenticationEvent;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Change email', 'description' => 'Update your email address. A verification link will be sent to the new address.'])]
class ChangeEmail extends Component
{
    public string $currentPassword = '';

    public string $email = '';

    public string $status = '';

    public string $error = '';

    public function requestChange(RequestEmailChange $requestEmailChange): void
    {
        $this->reset('status', 'error');
        $validated = $this->validate();

        /** @var User $user */
        $user = auth()->user();

        $requestEmailChange->execute($user, $validated['email'], request());

        $this->reset('currentPassword', 'email');
        $this->status = 'A verification link has been sent to your new email address.';
    }

    public function cancelPendingRequest(): void
    {
        $this->reset('status', 'error');

        /** @var User $user */
        $user = auth()->user();

        $pending = $user->emailChangeRequests()
            ->whereNull('verified_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $pending) {
            $this->error = 'No pending email change request found.';

            return;
        }

        $pending->forceFill(['cancelled_at' => now()])->save();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::EMAIL_CHANGE_CANCELLED,
            user: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        ));

        $this->status = 'Your pending email change request has been cancelled.';
    }

    protected function rules(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return [
            'currentPassword' => ['required', 'current_password'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (mb_strtolower($value) === mb_strtolower($user->email)) {
                        $fail('The new email must be different from your current email.');
                    }
                },
            ],
        ];
    }

    public function render(): mixed
    {
        /** @var User $user */
        $user = auth()->user();

        $pendingRequest = $user->emailChangeRequests()
            ->whereNull('verified_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->first();

        return view('livewire.authentication.change-email', [
            'pendingRequest' => $pendingRequest,
        ]);
    }
}
