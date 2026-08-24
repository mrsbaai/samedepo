<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\SignupUser;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Welcome to the club'])]
class Signup extends Component
{
    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function signup(SignupUser $signupUser): void
    {
        $this->email = mb_strtolower(trim($this->email));

        $validated = $this->validate();

        $signupUser->execute(
            email: $validated['email'],
            password: $validated['password'],
            request: request(),
        );

        $this->redirectRoute('verification.notice', navigate: true);
    }

    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed:passwordConfirmation',
                'different:email',
                Password::min((int) config('authentication.password.minimum_length'))
                    ->mixedCase()
                    ->numbers()
                    ->uncompromised(),
            ],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.authentication.signup', [
            'socialProviders' => config('authentication.social'),
        ]);
    }
}
