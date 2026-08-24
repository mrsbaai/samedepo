<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\SecurityHistory;
use App\Livewire\Authentication\SessionManager;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function createSession(User $user, string $id, string $ipAddress, string $userAgent): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);
}

it('renders current and other sessions with approved metadata', function (): void {
    $user = User::factory()->create();
    $currentSessionId = session()->getId();
    createSession($user, $currentSessionId, '127.0.0.1', 'Chrome on Windows');
    createSession($user, 'other-session', '203.0.113.10', 'Firefox on Linux');

    Livewire::actingAs($user)->test(SessionManager::class)
        ->assertSee('Current session')
        ->assertSee('127.0.0.1')
        ->assertSee('Chrome on Windows')
        ->assertSee('203.0.113.10')
        ->assertSee('Firefox on Linux');
});

it('cannot revoke the current session but revokes another active session', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $currentSessionId = session()->getId();
    createSession($user, $currentSessionId, '127.0.0.1', 'Current browser');
    createSession($user, 'revoke-me', '203.0.113.10', 'Other browser');

    Livewire::actingAs($user)->test(SessionManager::class)
        ->call('revoke', $currentSessionId)
        ->assertSet('error', 'You cannot revoke your current session.')
        ->call('revoke', 'revoke-me')
        ->assertSet('status', 'The session has been revoked.');

    expect(DB::table('sessions')->where('id', $currentSessionId)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'revoke-me')->exists())->toBeFalse();
    expect(SecurityEvent::query()->where('user_id', $user->id)->where('event_type', AuthenticationEvent::SESSION_REVOKED)->exists())->toBeTrue();
    Notification::assertSentTo($user, SecurityAlertNotification::class);
});

it('revokes all other sessions while preserving the current session', function (): void {
    $user = User::factory()->create();
    $currentSessionId = session()->getId();
    createSession($user, $currentSessionId, '127.0.0.1', 'Current browser');
    createSession($user, 'other-session-one', '203.0.113.10', 'Other browser');
    createSession($user, 'other-session-two', '198.51.100.10', 'Mobile browser');

    Livewire::actingAs($user)->test(SessionManager::class)
        ->call('revokeAllOtherSessions')
        ->assertSet('status', 'All other sessions have been revoked.');

    expect(DB::table('sessions')->where('id', $currentSessionId)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

it('paginates minimal security history without exposing metadata', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 16) as $index) {
        SecurityEvent::query()->create([
            'user_id' => $user->id,
            'event_type' => AuthenticationEvent::SESSION_REVOKED,
            'ip_address' => '203.0.113.'.$index,
            'user_agent' => 'Test browser',
            'metadata' => ['token' => 'must-not-render'],
            'occurred_at' => now()->subSeconds($index),
        ]);
    }

    Livewire::actingAs($user)->test(SecurityHistory::class)
        ->assertSee(AuthenticationEvent::SESSION_REVOKED)
        ->assertDontSee('must-not-render')
        ->call('nextPage')
        ->assertSee(AuthenticationEvent::SESSION_REVOKED);
});

it('protects session and security history routes with authentication', function (): void {
    $this->get(route('sessions.index'))->assertRedirect(route('signin'));
    $this->get(route('security-history.index'))->assertRedirect(route('signin'));
});

it('renders Flux session controls and history feedback states', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(SessionManager::class)
        ->assertSee('Active sessions')
        ->assertSee('Sign out all other sessions')
        ->assertSeeHtml('wire:loading');

    Livewire::actingAs($user)->test(SecurityHistory::class)
        ->assertSee('Security history')
        ->assertSee('No security activity has been recorded yet.');
});
