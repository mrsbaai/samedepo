<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\ChangeUserPassword;
use App\Models\User;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Change password', 'description' => 'Update your password and sign out other active sessions.'])]
class ChangePassword extends Component
{
    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $status = '';

    public function changePassword(ChangeUserPassword $changeUserPassword): void
    {
        $validated = $this->validate();

        /** @var User $user */
        $user = auth()->user();

        $changeUserPassword->execute($user, $validated['password'], request());
        $this->reset('currentPassword', 'password', 'passwordConfirmation');
        $this->status = 'Your password has been changed. Other sessions have been signed out.';
    }

    protected function rules(): array
    {
        return [
            'currentPassword' => ['required', 'current_password'],
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
        return view('livewire.authentication.change-password');
    }
}
