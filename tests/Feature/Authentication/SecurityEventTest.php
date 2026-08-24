<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

it('records a minimal security event without sensitive metadata', function (): void {
    $user = User::factory()->create();

    event(new AuthenticationEvent(
        type: AuthenticationEvent::PASSWORD_CHANGED,
        user: $user,
        ipAddress: '203.0.113.10',
        userAgent: 'Test Browser',
        metadata: [
            'password' => 'must-not-persist',
            'token' => 'must-not-persist',
            'device' => 'Chrome on Windows',
        ],
    ));

    $event = SecurityEvent::query()->whereBelongsTo($user)->sole();

    expect($event->event_type)->toBe(AuthenticationEvent::PASSWORD_CHANGED)
        ->and($event->ip_address)->toBe('203.0.113.10')
        ->and($event->metadata)->toBe(['device' => 'Chrome on Windows']);
});

it('uses a queued notification for security alerts', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $notification = new SecurityAlertNotification(AuthenticationEvent::TWO_FACTOR_ENABLED);
    $user->notify($notification);

    Notification::assertSentTo($user, SecurityAlertNotification::class);
    expect($notification)->toBeInstanceOf(ShouldQueue::class);
});
