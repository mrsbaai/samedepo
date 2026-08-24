<?php

declare(strict_types=1);

namespace App\Http\Controllers\Authentication;

use App\Actions\Authentication\HandleSocialiteCallback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SocialiteController
{
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->ensureEnabled($provider);

        try {
            return Socialite::driver($provider)->redirect();
        } catch (Throwable $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            report($e);

            return redirect()->route('signin')->withErrors(['email' => 'This social sign-in provider is currently unavailable.']);
        }
    }

    public function callback(Request $request, string $provider, HandleSocialiteCallback $callback): RedirectResponse
    {
        $this->ensureEnabled($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            report($e);

            return redirect()->route('signin')->withErrors(['email' => 'Unable to sign in. Please try again.']);
        }

        $result = $callback->execute($provider, $socialUser, $request);

        return match ($result['status']) {
            'linked', 'signed_in' => redirect()->intended('/'),
            'two_factor_required' => redirect()->route('two-factor.challenge'),
            'conflict', 'existing_email', 'missing_email' => redirect()->route('signin')->withErrors(['email' => $result['message']]),
            default => redirect()->route('signin')->withErrors(['email' => 'Unable to sign in. Please try again.']),
        };
    }

    private function ensureEnabled(string $provider): void
    {
        abort_unless(
            in_array($provider, array_keys(config('authentication.social')), true)
                && config("authentication.social.{$provider}.enabled") === true,
            404
        );
    }
}
