<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\ChangePassword;
use App\Models\User;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('requires the current password and a confirmed strong replacement password', function (): void {
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    Livewire::actingAs($user)->test(ChangePassword::class)
        ->set('currentPassword', 'incorrect-password')
        ->set('password', 'weak')
        ->set('passwordConfirmation', 'different')
        ->call('changePassword')
        ->assertHasErrors([
            'currentPassword' => 'current_password',
            'password',
        ]);
});

it('changes the password, preserves the current session, and revokes other sessions', function (): void {
    Notification::fake();
    $user = User::factory()->create(['password' => 'VioletRidge4829']);
    $currentSessionId = session()->getId();

    DB::table('sessions')->insert([
        [
            'id' => $currentSessionId,
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

    Livewire::actingAs($user)->test(ChangePassword::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->set('password', 'WinterHarbor4829')
        ->set('passwordConfirmation', 'WinterHarbor4829')
        ->call('changePassword')
        ->assertSet('status', 'Your password has been changed. Other sessions have been signed out.');

    expect(Hash::check('WinterHarbor4829', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', $currentSessionId)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'another-device-session')->exists())->toBeFalse();

    Notification::assertSentTo($user, SecurityAlertNotification::class);
});

it('records the password change and session revocation events', function (): void {
    Event::fake([AuthenticationEvent::class]);
    $user = User::factory()->create(['password' => 'VioletRidge4829']);
    DB::table('sessions')->insert([
        'id' => 'revoked-session-event',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Other browser',
        'payload' => 'other-payload',
        'last_activity' => now()->timestamp,
    ]);

    Livewire::actingAs($user)->test(ChangePassword::class)
        ->set('currentPassword', 'VioletRidge4829')
        ->set('password', 'WinterHarbor4829')
        ->set('passwordConfirmation', 'WinterHarbor4829')
        ->call('changePassword');

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::PASSWORD_CHANGED && $event->user?->is($user));
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::SESSION_REVOKED && $event->user?->is($user));
});

it('requires authentication to view the password change screen', function (): void {
    $this->get(route('password.change'))->assertRedirect(route('signin'));
});

it('renders Flux controls and loading state', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ChangePassword::class)
        ->assertSee('Current password')
        ->assertSee('New password')
        ->assertSee('Change password')
        ->assertSeeHtml('wire:loading');
});
