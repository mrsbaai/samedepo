<?php

use App\Models\EmailChangeRequest;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

test('guest can sign up', function () {
    $email = 'signup-'.Str::random().'@example.com';

    $this->browse(function (Browser $browser) use ($email) {
        $browser->visit('/signup')
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $email)
            ->type('input[name="password"]', 'BrowserTestPass123!')
            ->type('input[name="passwordConfirmation"]', 'BrowserTestPass123!');

        $browser->script("
            const el = document.querySelector('[wire\\\\:id]');
            const id = el ? el.getAttribute('wire:id') : null;
            if (id && window.Livewire) { window.Livewire.find(id).set('termsAccepted', true); }
        ");

        $browser->waitFor('button[type="submit"]:enabled')
            ->press('Sign up')
            ->waitForLocation('/verify-email')
            ->assertPathIs('/verify-email')
            ->assertSee('Verify your email');
    });
});

test('guest can sign in', function () {
    $user = User::factory()->create([
        'email' => 'signin-'.Str::random().'@example.com',
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->driver->manage()->deleteAllCookies();
        $browser->visit('/signin')
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $user->email)
            ->type('input[name="password"]', 'password')
            ->press('Sign in')
            ->waitForLocation('/dashboard')
            ->assertPathIs('/dashboard')
            ->assertSee('Welcome back');
    });
});

test('guest can recover password by otp', function () {
    $user = User::factory()->create([
        'email' => 'recovery-'.Str::random().'@example.com',
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->driver->manage()->deleteAllCookies();

        $browser->visit('/forgot-password')
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $user->email)
            ->press('Send recovery code')
            ->waitForLocation('/verify-otp');

        $challenge = OtpChallenge::query()
            ->where('email', $user->email)
            ->where('purpose', 'password_recovery')
            ->latest('id')
            ->first();

        if ($challenge) {
            $challenge->forceFill(['code' => Hash::make('123456')])->save();
        }

        $browser->waitFor('input[aria-label="Verification digit 1"]');

        $browser->script("
            const el = document.querySelector('form[wire\\\\:submit=\"verify\"]');
            const id = el ? el.getAttribute('wire:id') : null;
            if (id && window.Livewire) { window.Livewire.find(id).set('code', '123456'); }
        ");

        $browser->waitFor('button[type="submit"]:enabled')
            ->press('Verify code')
            ->waitForLocation('/reset-password')
            ->assertPathIs('/reset-password')
            ->type('input[name="password"]', 'NewBrowserPass123!')
            ->type('input[name="passwordConfirmation"]', 'NewBrowserPass123!')
            ->press('Reset password')
            ->waitForLocation('/signin')
            ->assertPathIs('/signin');
    });
});

test('user can change password', function () {
    $user = User::factory()->create([
        'email' => 'change-password-'.Str::random().'@example.com',
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->driver->manage()->deleteAllCookies();

        $browser->visit('/signin')
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $user->email)
            ->type('input[name="password"]', 'password')
            ->waitFor('button[type="submit"]:enabled')
            ->press('Sign in')
            ->waitForLocation('/dashboard')
            ->assertPathIs('/dashboard');

        $browser->visit('/security/change-password')
            ->waitFor('input[name="currentPassword"]')
            ->type('input[name="currentPassword"]', 'password')
            ->type('input[name="password"]', 'NewBrowserPass123!')
            ->type('input[name="passwordConfirmation"]', 'NewBrowserPass123!')
            ->waitFor('button[type="submit"]:enabled')
            ->press('Change password')
            ->waitForText('Your password has been changed. Other sessions have been signed out.');
    });
});

test('guest with two factor can log in after challenge', function () {
    $user = User::factory()->create([
        'email' => '2fa-'.Str::random().'@example.com',
    ]);

    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->browse(function (Browser $browser) use ($user, $secret) {
        $browser->driver->manage()->deleteAllCookies();

        $browser->visit('/signin')
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $user->email)
            ->type('input[name="password"]', 'password')
            ->waitFor('button[type="submit"]:enabled')
            ->press('Sign in')
            ->waitForLocation('/two-factor-challenge')
            ->assertPathIs('/two-factor-challenge')
            ->waitFor('input[aria-label="Verification digit 1"]');

        $code = (new Google2FA)->getCurrentOtp($secret);

        $browser->script("
            const el = document.querySelector('[wire\\\\:name=\"authentication.two-factor-challenge\"]');
            const id = el ? el.getAttribute('wire:id') : null;
            if (id && window.Livewire) { window.Livewire.find(id).set('code', '".$code."'); }
        ");

        $browser->waitFor('button[type="submit"]:enabled')
            ->press('Verify your identity')
            ->waitForLocation('/dashboard')
            ->assertPathIs('/dashboard');
    });
});

test('user can request account deletion', function () {
    $user = User::factory()->create([
        'email' => 'delete-'.Str::random().'@example.com',
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->driver->manage()->deleteAllCookies();

        $browser->visit('/signin')
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $user->email)
            ->type('input[name="password"]', 'password')
            ->waitFor('button[type="submit"]:enabled')
            ->press('Sign in')
            ->waitForLocation('/dashboard')
            ->assertPathIs('/dashboard');

        $browser->visit('/security/delete')
            ->waitFor('input[name="currentPassword"]')
            ->type('input[name="currentPassword"]', 'password');

        $browser->script("
            const el = document.querySelector('form[wire\\\\:submit=\"requestDeletion\"]');
            const id = el ? el.getAttribute('wire:id') : null;
            if (id && window.Livewire) { window.Livewire.find(id).set('confirmDeletion', true); }
        ");

        $browser->waitFor('button[type="submit"]:enabled')
            ->press('Request account deletion')
            ->waitForLocation('/signin')
            ->assertPathIs('/signin');

        expect($user->fresh()->deletion_requested_at)->not->toBeNull();
    });
});

test('user can change email', function () {
    $user = User::factory()->create([
        'email' => 'old-'.Str::random().'@example.com',
    ]);
    $newEmail = 'new-'.Str::random().'@example.com';

    $this->browse(function (Browser $browser) use ($user, $newEmail) {
        $browser->driver->manage()->deleteAllCookies();

        $browser->visit('/signin')
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $user->email)
            ->type('input[name="password"]', 'password')
            ->waitFor('button[type="submit"]:enabled')
            ->press('Sign in')
            ->waitForLocation('/dashboard')
            ->assertPathIs('/dashboard');

        $browser->visit('/security/change-email')
            ->waitFor('input[name="currentPassword"]')
            ->type('input[name="currentPassword"]', 'password')
            ->type('input[name="email"]', $newEmail)
            ->waitFor('button[type="submit"]:enabled')
            ->press('Request email change')
            ->waitForText('A verification link has been sent to your new email address.');

        $logPath = storage_path('logs/laravel.log');
        $url = null;

        if (File::exists($logPath)) {
            $log = File::get($logPath);
            if (preg_match_all('/email\/verify-change\/(\d+)\/([A-Za-z0-9]+)/', $log, $matches, PREG_SET_ORDER)) {
                $latest = end($matches);
                $url = '/email/verify-change/'.$latest[1].'/'.$latest[2];
            }
        }

        if (! $url) {
            $changeRequest = EmailChangeRequest::query()
                ->where('user_id', $user->id)
                ->whereNull('verified_at')
                ->whereNull('cancelled_at')
                ->latest('id')
                ->first();

            if ($changeRequest) {
                $url = '/email/verify-change/'.$changeRequest->id.'/placeholder';
            }
        }

        if ($url) {
            $browser->visit($url)
                ->waitForLocation('/security')
                ->assertPathIs('/security');
        }

        expect($user->fresh()->email)->toBe($newEmail);
    });
});
