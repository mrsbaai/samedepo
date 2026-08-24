<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\Signin;
use App\Livewire\Authentication\TwoFactorChallenge;
use App\Livewire\Authentication\TwoFactorSecurity;
use App\Models\User;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

function twoFactorCode(User $user): string
{
    return (new Google2FA)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
}

it('sets up and confirms Fortify TOTP while exposing setup data only during controlled setup', function (): void {
    Event::fake([AuthenticationEvent::class]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TwoFactorSecurity::class)
        ->call('beginSetup')
        ->assertSet('setupInProgress', true)
        ->assertSee('Manual setup key')
        ->assertSee('Confirm and enable');

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->getRawOriginal('two_factor_secret'))->not->toContain(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));

    Livewire::actingAs($user)->test(TwoFactorSecurity::class)
        ->set('code', twoFactorCode($user))
        ->call('confirmSetup')
        ->assertSet('status', 'Two-factor authentication has been enabled.')
        ->assertSee('Recovery codes');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::TWO_FACTOR_ENABLED && $event->user?->is($user));
});

it('allows cancelling two-factor setup before it is confirmed', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TwoFactorSecurity::class)
        ->call('beginSetup')
        ->assertSet('setupInProgress', true)
        ->call('cancelSetup')
        ->assertSet('setupInProgress', false);

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('challenges a TOTP-enabled sign-in and accepts a valid authenticator code', function (): void {
    Event::fake([AuthenticationEvent::class]);
    $user = User::factory()->create(['password' => 'VioletRidge4829']);
    enableTwoFactor($user);
    auth()->logout();

    Livewire::test(Signin::class)
        ->set('email', $user->email)
        ->set('password', 'VioletRidge4829')
        ->call('signin')
        ->assertRedirect(route('two-factor.challenge'));

    expect(auth()->check())->toBeFalse()
        ->and(session('signin.id'))->toBe($user->id);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', twoFactorCode($user))
        ->call('verify')
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::USER_SIGNED_IN && $event->user?->is($user));
});

it('uses a recovery code once during the sign-in challenge', function (): void {
    $user = User::factory()->create();
    enableConfirmedTwoFactor($user);
    $code = $user->fresh()->recoveryCodes()[0];
    session(['signin.id' => $user->id]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('recoveryCode', $code)
        ->call('verifyRecoveryCode')
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->recoveryCodes())->not->toContain($code)
        ->and(auth()->id())->toBe($user->id);
});

it('regenerates recovery codes only after current-password confirmation', function (): void {
    Notification::fake();
    $user = User::factory()->create(['password' => 'VioletRidge4829']);
    enableConfirmedTwoFactor($user);
    $oldCodes = $user->fresh()->recoveryCodes();

    Livewire::actingAs($user)->test(TwoFactorSecurity::class)
        ->set('currentPassword', 'incorrect-password')
        ->call('regenerateRecoveryCodes')
        ->assertHasErrors(['currentPassword' => 'current_password']);

    Livewire::actingAs($user)->test(TwoFactorSecurity::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->call('regenerateRecoveryCodes')
        ->assertSet('status', 'New recovery codes have been generated.')
        ->assertSee('Recovery codes');

    expect($user->fresh()->recoveryCodes())->not->toEqual($oldCodes);
    Notification::assertSentTo($user, SecurityAlertNotification::class);
});

it('requires current-password confirmation before disabling TOTP and removes encrypted credentials', function (): void {
    Event::fake([AuthenticationEvent::class]);
    $user = User::factory()->create(['password' => 'VioletRidge4829']);
    enableConfirmedTwoFactor($user);

    Livewire::actingAs($user)->test(TwoFactorSecurity::class)
        ->set('currentPassword', 'incorrect-password')
        ->call('disable')
        ->assertHasErrors(['currentPassword' => 'current_password']);

    Livewire::actingAs($user)->test(TwoFactorSecurity::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->call('disable')
        ->assertSet('status', 'Two-factor authentication has been disabled.');

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->two_factor_recovery_codes)->toBeNull();
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::TWO_FACTOR_DISABLED && $event->user?->is($user));
});

it('renders Flux controls and the approved OTP primitive', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TwoFactorSecurity::class)
        ->assertSee('Set up two-factor authentication')
        ->assertSeeHtml('wire:loading');

    session(['signin.id' => $user->id]);
    Livewire::test(TwoFactorChallenge::class)
        ->assertSee('Authenticator code')
        ->assertSeeHtml('inputmode="numeric"');
});

function enableTwoFactor(User $user): void
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();

    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
}

function enableConfirmedTwoFactor(User $user): void
{
    $component = Livewire::actingAs($user)->test(TwoFactorSecurity::class);
    $component->call('beginSetup');
    $user->refresh();
    $component->set('code', twoFactorCode($user))->call('confirmSetup');
    $user->refresh();
}
