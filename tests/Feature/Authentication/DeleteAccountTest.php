<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\DeleteAccount;
use App\Livewire\Authentication\Signin;
use App\Models\OtpChallenge;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

it('requires authentication to access the delete account page', function (): void {
    $this->get(route('security.delete'))->assertRedirect(route('signin'));
});

it('renders the deletion warning and password confirmation controls', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(DeleteAccount::class)
        ->assertSee('Deleting your account')
        ->assertSee('Current password')
        ->assertSee('I understand')
        ->assertSee('Request account deletion')
        ->assertSeeHtml('wire:loading');
});

it('requires current password and confirmation to request deletion', function (): void {
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    Livewire::actingAs($user)->test(DeleteAccount::class)
        ->set('currentPassword', 'wrong-password')
        ->set('confirmDeletion', false)
        ->call('requestDeletion')
        ->assertHasErrors(['currentPassword', 'confirmDeletion']);
});

it('requests deletion, deactivates the account, and revokes all sessions', function (): void {
    Notification::fake();

    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    DB::table('sessions')->insert([
        [
            'id' => session()->getId(),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Current browser',
            'payload' => 'current-payload',
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => 'another-device-session',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Other browser',
            'payload' => 'other-payload',
            'last_activity' => now()->timestamp,
        ],
    ]);

    Livewire::actingAs($user)->test(DeleteAccount::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->set('confirmDeletion', true)
        ->call('requestDeletion')
        ->assertRedirect('/signin');

    expect($user->fresh())
        ->is_active->toBeFalse()
        ->deletion_requested_at->not->toBeNull();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    Notification::assertSentTo($user, SecurityAlertNotification::class);

    expect(SecurityEvent::query()->where('user_id', $user->id)->where('event_type', AuthenticationEvent::ACCOUNT_DELETED)->exists())->toBeTrue();
});

it('prevents sign-in while the account is scheduled for deletion', function (): void {
    $user = User::factory()->create([
        'password' => 'VioletRidge4829',
        'is_active' => false,
        'deletion_requested_at' => now()->subDay(),
        'email_verified_at' => now(),
    ]);

    Livewire::test(Signin::class)
        ->set('email', $user->email)
        ->set('password', 'VioletRidge4829')
        ->call('signin')
        ->assertSet('error', 'These credentials do not match our records.');
});

it('recovers an account via the signed recovery link during the grace period', function (): void {
    Event::fake([AuthenticationEvent::class]);

    $user = User::factory()->create([
        'email' => 'recover@example.com',
        'is_active' => false,
        'deletion_requested_at' => now()->subDay(),
    ]);

    $url = URL::signedRoute('security.delete.recover', [
        'id' => $user->id,
        'email' => $user->email,
    ]);

    $this->get($url)
        ->assertRedirect(route('signin'))
        ->assertSessionHas('status');

    expect($user->fresh())
        ->is_active->toBeTrue()
        ->deletion_requested_at->toBeNull();

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $e): bool => $e->type === AuthenticationEvent::ACCOUNT_DELETION_RECOVERED && $e->user?->is($user));
});

it('rejects recovery with an invalid or expired signed link', function (): void {
    $user = User::factory()->create([
        'email' => 'bad@example.com',
        'is_active' => false,
        'deletion_requested_at' => now()->subDay(),
    ]);

    $this->get('/security/delete/recover?id=999&email=missing@example.com')
        ->assertRedirect(route('signin'))
        ->assertSessionHasErrors('email');

    $expiredUrl = URL::signedRoute('security.delete.recover', [
        'id' => $user->id,
        'email' => $user->email,
    ], now()->subMinute());

    $this->get($expiredUrl)
        ->assertRedirect(route('signin'))
        ->assertSessionHasErrors('email');

    expect($user->fresh()->is_active)->toBeFalse();
});

it('permanently deletes accounts whose grace period has expired', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'cleanup@example.com',
        'is_active' => false,
        'deletion_requested_at' => now()->subDays(31),
    ]);

    $this->artisan('authentication:cleanup-deleted-accounts')->assertSuccessful();

    expect(User::query()->where('id', $user->id)->exists())->toBeFalse();

    Notification::assertSentOnDemand(SecurityAlertNotification::class, function (SecurityAlertNotification $notification): bool {
        return $notification->eventType() === 'account_deletion_completed';
    });
});

it('does not delete accounts still within the grace period', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'keep@example.com',
        'is_active' => false,
        'deletion_requested_at' => now()->subDays(29),
    ]);

    $this->artisan('authentication:cleanup-deleted-accounts')->assertSuccessful();

    expect(User::query()->where('id', $user->id)->exists())->toBeTrue();

    Notification::assertNothingSent();
});

it('cleans up related authentication records when permanently deleting', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'is_active' => false,
        'deletion_requested_at' => now()->subDays(31),
    ]);

    $user->otpChallenges()->create([
        'email' => $user->email,
        'code' => hash('sha256', '123456'),
        'purpose' => 'password_reset',
        'expires_at' => now()->addHour(),
    ]);

    $user->securityEvents()->create([
        'event_type' => AuthenticationEvent::USER_SIGNED_IN,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'occurred_at' => now(),
    ]);

    $this->artisan('authentication:cleanup-deleted-accounts')->assertSuccessful();

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(SecurityEvent::query()->count())->toBe(0);
});
