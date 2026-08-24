<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\ResolvePostSigninRedirect;
use App\Actions\Authentication\SigninUser;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Welcome back'])]
class Signin extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public string $error = '';

    public function signin(SigninUser $signinUser): void
    {
        $this->email = mb_strtolower(trim($this->email));
        $this->validate();

        $key = 'signin|'.$this->email.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('authentication.rate_limits.signin'))) {
            $this->error = 'Too many sign-in attempts. Please try again later.';

            return;
        }

        if (! $signinUser->execute($this->email, $this->password, $this->remember, request())) {
            $this->error = 'These credentials do not match our records.';

            return;
        }

        if (session()->has('signin.id')) {
            $this->redirectRoute('two-factor.challenge', navigate: true);

            return;
        }

        /** @var User $user */
        $user = auth()->user();

        $this->redirect(ResolvePostSigninRedirect::for($user), navigate: true);
    }

    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.authentication.signin', [
            'socialProviders' => config('authentication.social'),
            'rememberDays' => (int) config('authentication.remember.days', 30),
        ]);
    }
}
