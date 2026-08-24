<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\ChangeEmail;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\Authentication\EmailChangeVerificationNotification;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('requires current password and a unique target email to request a change', function (): void {
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->set('currentPassword', 'wrong-password')
        ->set('email', 'not-an-email')
        ->call('requestChange')
        ->assertHasErrors(['currentPassword', 'email']);
});

it('rejects email change to the same address', function (): void {
    $user = User::factory()->create(['password' => 'VioletRidge4829', 'email' => 'existing@example.com']);

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->set('email', 'existing@example.com')
        ->call('requestChange')
        ->assertHasErrors(['email']);
});

it('rejects email change to an already-used email', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['password' => 'VioletRidge4829', 'email' => 'me@example.com']);

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->set('email', 'taken@example.com')
        ->call('requestChange')
        ->assertHasErrors(['email']);
});

it('creates a pending request and sends verification to the new email', function (): void {
    Notification::fake();
    $user = User::factory()->create(['password' => 'VioletRidge4829', 'email' => 'old@example.com']);

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->set('email', 'new@example.com')
        ->call('requestChange')
        ->assertSet('status', 'A verification link has been sent to your new email address.')
        ->assertHasNoErrors();

    $pending = EmailChangeRequest::query()
        ->where('user_id', $user->id)
        ->whereNull('verified_at')
        ->whereNull('cancelled_at')
        ->first();

    expect($pending)->not->toBeNull()
        ->and($pending->pending_email)->toBe('new@example.com')
        ->and($pending->expires_at)->toBeGreaterThan(now());

    Notification::assertSentOnDemand(EmailChangeVerificationNotification::class);
});

it('cancels previous pending requests when a new one is created', function (): void {
    Notification::fake();
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    $oldRequest = $user->emailChangeRequests()->create([
        'pending_email' => 'first@example.com',
        'verification_token' => hash('sha256', 'old-token'),
        'expires_at' => now()->addHour(),
    ]);

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->set('email', 'second@example.com')
        ->call('requestChange')
        ->assertHasNoErrors();

    expect($oldRequest->fresh()->cancelled_at)->not->toBeNull();
});

it('expires pending requests after the configured time', function (): void {
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    $expired = $user->emailChangeRequests()->create([
        'pending_email' => 'expired@example.com',
        'verification_token' => hash('sha256', 'expired-token'),
        'expires_at' => now()->subMinute(),
    ]);

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->assertDontSee('Pending email change');
});

it('can cancel a pending email change request', function (): void {
    Event::fake([AuthenticationEvent::class]);
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    $pending = $user->emailChangeRequests()->create([
        'pending_email' => 'cancel-me@example.com',
        'verification_token' => hash('sha256', 'cancel-token'),
        'expires_at' => now()->addHour(),
    ]);

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->call('cancelPendingRequest')
        ->assertSet('status', 'Your pending email change request has been cancelled.');

    expect($pending->fresh()->cancelled_at)->not->toBeNull();

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $e): bool => $e->type === AuthenticationEvent::EMAIL_CHANGE_CANCELLED && $e->user?->is($user));
});

it('shows error when cancelling with no pending request', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->call('cancelPendingRequest')
        ->assertSet('error', 'No pending email change request found.');
});

it('verifies the new email and updates the user email', function (): void {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = Str::random(64);

    $changeRequest = $user->emailChangeRequests()->create([
        'pending_email' => 'verified-new@example.com',
        'verification_token' => hash('sha256', $token),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get(route('email.verify-change', ['token' => $token, 'id' => $changeRequest->id]))
        ->assertRedirect(route('security'))
        ->assertSessionHas('status', 'Your email address has been updated.');

    expect($user->fresh()->email)->toBe('verified-new@example.com')
        ->and($changeRequest->fresh()->verified_at)->not->toBeNull();
});

it('rejects expired or invalid verification tokens', function (): void {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $expired = $user->emailChangeRequests()->create([
        'pending_email' => 'expired@example.com',
        'verification_token' => hash('sha256', 'good-token'),
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->get(route('email.verify-change', ['token' => 'good-token', 'id' => $expired->id]))
        ->assertRedirect(route('security'))
        ->assertSessionHasErrors('verification');
});

it('rejects verification with a wrong token', function (): void {
    $user = User::factory()->create();

    $changeRequest = $user->emailChangeRequests()->create([
        'pending_email' => 'wrong@example.com',
        'verification_token' => hash('sha256', 'correct-token'),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get(route('email.verify-change', ['token' => 'wrong-token', 'id' => $changeRequest->id]))
        ->assertRedirect(route('security'))
        ->assertSessionHasErrors('verification');
});

it('rejects verification by a different user', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $token = Str::random(64);

    $changeRequest = $owner->emailChangeRequests()->create([
        'pending_email' => 'steal@example.com',
        'verification_token' => hash('sha256', $token),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($intruder)
        ->get(route('email.verify-change', ['token' => $token, 'id' => $changeRequest->id]))
        ->assertRedirect(route('security'))
        ->assertSessionHasErrors('verification');
});

it('records security events for request, cancellation, and completion', function (): void {
    Event::fake([AuthenticationEvent::class]);
    Notification::fake();
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->set('email', 'event-test@example.com')
        ->call('requestChange');

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $e): bool => $e->type === AuthenticationEvent::EMAIL_CHANGE_REQUESTED && $e->user?->is($user));
});

it('records the email changed event on verification', function (): void {
    Event::fake([AuthenticationEvent::class]);
    $user = User::factory()->create(['email' => 'before@example.com']);
    $token = Str::random(64);

    $changeRequest = $user->emailChangeRequests()->create([
        'pending_email' => 'after@example.com',
        'verification_token' => hash('sha256', $token),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get(route('email.verify-change', ['token' => $token, 'id' => $changeRequest->id]));

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $e): bool => $e->type === AuthenticationEvent::EMAIL_CHANGED && $e->user?->is($user));
});

it('requires authentication to access the email change page', function (): void {
    $this->get(route('email.change'))->assertRedirect(route('signin'));
});

it('renders Flux controls and loading states', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ChangeEmail::class)
        ->assertSee('Current password')
        ->assertSee('New email address')
        ->assertSee('Request email change')
        ->assertSeeHtml('wire:loading');
});

it('sends a security notification to the old email when email changes', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old-notify@example.com']);
    $token = Str::random(64);

    $changeRequest = $user->emailChangeRequests()->create([
        'pending_email' => 'new-notify@example.com',
        'verification_token' => hash('sha256', $token),
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->get(route('email.verify-change', ['token' => $token, 'id' => $changeRequest->id]));

    Notification::assertSentTo($user, SecurityAlertNotification::class);
});
