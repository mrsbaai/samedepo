<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\Signup;
use App\Livewire\Authentication\VerifyEmailNotice;
use App\Models\User;
use App\Notifications\Authentication\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

it('validates signup fields', function (): void {
    Livewire::test(Signup::class)
        ->set('email', 'not-an-email')
        ->set('password', 'weak')
        ->set('passwordConfirmation', 'different')
        ->set('acceptedTerms', true)
        ->call('signup')
        ->assertHasErrors([
            'email' => 'email',
            'password',
        ]);
});

it('rejects a password that matches the email', function (): void {
    Livewire::test(Signup::class)
        ->set('email', 'taylor@example.test')
        ->set('password', 'taylor@example.test')
        ->set('passwordConfirmation', 'taylor@example.test')
        ->set('acceptedTerms', true)
        ->call('signup')
        ->assertHasErrors(['password' => 'different']);
});

it('requires accepting the terms of service and privacy policy', function (): void {
    Livewire::test(Signup::class)
        ->set('email', 'taylor@example.test')
        ->set('password', 'VioletRidge4829')
        ->set('passwordConfirmation', 'VioletRidge4829')
        ->set('acceptedTerms', false)
        ->call('signup')
        ->assertHasErrors(['acceptedTerms' => 'accepted']);
});

it('signs up an inactive unverified user and sends a queued verification notification', function (): void {
    Event::fake([AuthenticationEvent::class]);
    Notification::fake();

    Livewire::test(Signup::class)
        ->set('email', '  TAYLOR@EXAMPLE.TEST ')
        ->set('password', 'VioletRidge4829')
        ->set('passwordConfirmation', 'VioletRidge4829')
        ->set('acceptedTerms', true)
        ->call('signup')
        ->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'taylor@example.test')->sole();

    expect($user->is_active)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->terms_accepted_at)->not->toBeNull()
        ->and(auth()->id())->toBe($user->id);

    Notification::assertSentTo($user, VerifyEmailNotification::class);
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::USER_SIGNED_UP && $event->user?->is($user));
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::EMAIL_VERIFICATION_REQUESTED && $event->user?->is($user));
});

it('rejects a duplicate email regardless of casing', function (): void {
    User::factory()->create(['email' => 'taylor@example.test']);

    Livewire::test(Signup::class)
        ->set('email', 'TAYLOR@EXAMPLE.TEST')
        ->set('password', 'VioletRidge4829')
        ->set('passwordConfirmation', 'VioletRidge4829')
        ->set('acceptedTerms', true)
        ->call('signup')
        ->assertHasErrors(['email' => 'unique']);
});

it('verifies an authenticated user through a valid signed URL', function (): void {
    Event::fake([AuthenticationEvent::class, Verified::class]);
    $user = User::factory()->unverified()->create(['is_active' => false]);
    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes((int) config('authentication.email_verification.expires_after_minutes')),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($url)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'Your email address has been verified.');

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue();

    Event::assertDispatched(Verified::class);
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::EMAIL_VERIFIED && $event->user?->is($user));
});

it('returns verification feedback for invalid and expired links', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('verification.verify', ['id' => $user->id, 'hash' => 'invalid']))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHasErrors('verification');

    $expiredUrl = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($expiredUrl)
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHasErrors('verification');
});

it('rejects an incorrect email verification code', function (): void {
    $user = User::factory()->unverified()->create(['is_active' => false]);
    $user->sendEmailVerificationNotification();

    Livewire::actingAs($user)->test(VerifyEmailNotice::class)
        ->set('code', '000000')
        ->call('verify')
        ->assertSet('error', 'This code is invalid or has expired.');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('verifies an authenticated user by entering the emailed 6-digit code', function (): void {
    Event::fake([AuthenticationEvent::class, Verified::class]);
    Notification::fake();
    $user = User::factory()->unverified()->create(['is_active' => false]);

    $user->sendEmailVerificationNotification();

    $code = null;
    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use (&$code): bool {
        $code = (new ReflectionProperty($notification, 'code'))->getValue($notification);

        return true;
    });

    Livewire::actingAs($user)->test(VerifyEmailNotice::class)
        ->set('code', $code)
        ->call('verify')
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue();

    Event::assertDispatched(Verified::class);
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::EMAIL_VERIFIED && $event->user?->is($user));
});

it('throttles verification email resends without revealing account state', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    Livewire::actingAs($user)->test(VerifyEmailNotice::class)
        ->call('resend')
        ->assertSet('status', 'A new verification code has been sent.')
        ->call('resend')
        ->assertSet('status', 'Please wait before requesting another code.');

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
});

it('redirects an already verified user away from the verification notice', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    Livewire::actingAs($user)->test(VerifyEmailNotice::class)
        ->assertRedirect(route('dashboard'));
});
