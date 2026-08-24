<?php

use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Support\TicketAutoClosedNotification;
use Illuminate\Support\Facades\Notification;

test('it closes open tickets inactive longer than the configured threshold and notifies the owner', function () {
    Notification::fake();
    config(['support.auto_close_after_days' => 7]);

    $user = User::factory()->create();

    $inactive = SupportTicket::create([
        'user_id' => $user->id,
        'subject' => 'Old ticket',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now()->subDays(8),
    ]);

    $active = SupportTicket::create([
        'user_id' => $user->id,
        'subject' => 'Recent ticket',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now()->subDays(1),
    ]);

    $alreadyClosed = SupportTicket::create([
        'user_id' => $user->id,
        'subject' => 'Already closed',
        'status' => SupportTicket::STATUS_CLOSED,
        'last_message_at' => now()->subDays(30),
    ]);

    $this->artisan('support:close-inactive-tickets')->assertSuccessful();

    expect($inactive->refresh()->status)->toBe(SupportTicket::STATUS_CLOSED)
        ->and($active->refresh()->status)->toBe(SupportTicket::STATUS_OPEN)
        ->and($alreadyClosed->refresh()->status)->toBe(SupportTicket::STATUS_CLOSED);

    Notification::assertSentTo($user, TicketAutoClosedNotification::class, function ($notification) use ($inactive) {
        return $notification->toMail($inactive->user)->subject === 'Ticket closed due to inactivity: '.$inactive->subject;
    });

    Notification::assertSentToTimes($user, TicketAutoClosedNotification::class, 1);
});

test('it does nothing when there are no inactive tickets', function () {
    Notification::fake();

    $user = User::factory()->create();
    SupportTicket::create([
        'user_id' => $user->id,
        'subject' => 'Fresh ticket',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $this->artisan('support:close-inactive-tickets')->assertSuccessful();

    Notification::assertNothingSent();
});
