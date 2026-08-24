<?php

use App\Livewire\Admin\TicketManager;
use App\Livewire\Support\SupportCenter;
use App\Livewire\Support\TicketCreate;
use App\Livewire\Support\TicketThread;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Support\NewTicketMessageNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('guests cannot create a ticket', function () {
    $this->get(route('support.tickets.create'))->assertRedirect(route('signin'));
});

test('a user can open a ticket, which notifies admins with the app name, the message, and any attachment', function () {
    Notification::fake();
    Storage::fake('public');

    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($user)
        ->test(TicketCreate::class)
        ->set('subject', 'I need help')
        ->set('body', 'Something is broken.')
        ->set('image', UploadedFile::fake()->image('screenshot.png'))
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('support', ['tab' => 'tickets']));

    $ticket = SupportTicket::where('user_id', $user->id)->firstOrFail();

    expect($ticket->subject)->toBe('I need help')
        ->and($ticket->status)->toBe(SupportTicket::STATUS_OPEN)
        ->and($ticket->messages()->count())->toBe(1);

    Notification::assertSentTo($admin, NewTicketMessageNotification::class, function ($notification) use ($admin) {
        $mail = $notification->toMail($admin);
        $rendered = json_encode($mail);

        return str_contains($mail->subject, config('app.name'))
            && str_contains($rendered, 'Something is broken.')
            && str_contains($rendered, 'support-tickets');
    });
});

test('a user replying on an existing ticket notifies admins with the message content', function () {
    Notification::fake();

    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($user)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'Any update on this?')
        ->call('reply')
        ->assertHasNoErrors();

    Notification::assertSentTo($admin, NewTicketMessageNotification::class, function ($notification) use ($admin) {
        $mail = $notification->toMail($admin);

        return str_contains(json_encode($mail), 'Any update on this?');
    });
});

test('an admin reply notifies the ticket owner without the message content', function () {
    Notification::fake();

    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'Secret internal reply content.')
        ->call('reply')
        ->assertHasNoErrors();

    Notification::assertSentTo($user, NewTicketMessageNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);
        $rendered = json_encode($mail);

        return str_contains($mail->subject, config('app.name'))
            && ! str_contains($rendered, 'Secret internal reply content.');
    });
});

test('visiting the new ticket page while one is open redirects to it instead of allowing a second', function () {
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'First', 'status' => 'open', 'last_message_at' => now()]);

    $this->actingAs($user)
        ->get(route('support.tickets.create'))
        ->assertRedirect(route('support.tickets.show', $ticket));

    expect(SupportTicket::where('user_id', $user->id)->count())->toBe(1);
});

test('creating a ticket while one is open redirects to the existing ticket', function () {
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'First', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($user)
        ->test(TicketCreate::class)
        ->assertRedirect(route('support.tickets.show', $ticket));
});

test('a user cannot view another user\'s ticket', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $owner->id, 'subject' => 'Private', 'status' => 'open', 'last_message_at' => now()]);

    $this->actingAs($intruder)
        ->get(route('support.tickets.show', $ticket))
        ->assertForbidden();
});

test('admin can reply to a ticket using the configured agent name, which notifies the ticket owner', function () {
    Notification::fake();
    \App\Models\SupportIdentity::forRole('support')->update(['name' => 'Alex']);

    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'First message']);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'We are looking into it.')
        ->call('reply')
        ->assertHasNoErrors();

    // The `messages()` relation orders by `created_at`, which two fast inserts in the
    // same test can tie on (second-level precision) — reorder by `id` explicitly so we
    // deterministically fetch the reply regardless of timestamp ties.
    $reply = $ticket->messages()->reorder('id', 'desc')->first();

    expect($ticket->messages()->count())->toBe(2)
        ->and($reply->author_name)->toBe('Alex from Support'); // "{agent_name} from Support"

    Notification::assertSentTo($user, NewTicketMessageNotification::class);
});

test('changing the agent name does not rename messages already sent', function () {
    \App\Models\SupportIdentity::forRole('support')->update(['name' => 'Alex']);

    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'First reply.')
        ->call('reply');

    \App\Models\SupportIdentity::forRole('support')->update(['name' => 'Jordan']);

    $firstReply = $ticket->messages()->reorder('id', 'desc')->first();

    expect($firstReply->author_name)->toBe('Alex from Support');
});

test('the agent name is displayed as "{name} from Support"', function () {
    \App\Models\SupportIdentity::forRole('support')->update(['name' => 'Robert']);

    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin-secret@example.test']);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => null, 'body' => 'Legacy reply with no name snapshot']);

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Robert from Support')
        ->assertDontSee('admin-secret@example.test');
});

test('an unset agent name falls back to just "Support"', function () {
    \App\Models\SupportIdentity::forRole('support')->update(['name' => null]);

    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => null, 'body' => 'Legacy reply with no name snapshot']);

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Support');
});

test('opening a ticket marks the other party\'s messages as read', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $message = $ticket->messages()->create(['user_id' => $user->id, 'body' => 'First message']);

    expect($message->refresh()->isRead())->toBeFalse();

    Livewire::actingAs($admin)->test(TicketThread::class, ['ticket' => $ticket]);

    expect($message->refresh()->isRead())->toBeTrue();
});

test('a user can attach an image to a reply', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($user)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'Here is a screenshot.')
        ->set('image', UploadedFile::fake()->image('screenshot.png'))
        ->call('reply')
        ->assertHasNoErrors();

    $message = $ticket->messages()->reorder('id', 'desc')->first();

    expect($message->image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($message->image_path);
});

test('selecting an image shows the inline attachment preview with a remove control', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($user)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('image', UploadedFile::fake()->image('screenshot.png'))
        ->assertSee('Attachment preview', false)
        ->set('image', null)
        ->assertDontSee('Attachment preview', false);
});

test('only the admin sees a seen/sent indicator, and only on their own messages', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'My own message']);
    $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply']);

    // Before the user has opened the ticket, the admin sees their own reply marked "Sent".
    $this->actingAs($admin)
        ->get(route('admin.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Sent');

    // The ticket owner never sees a seen/sent indicator, not even on their own message —
    // and opening the page marks the admin's reply as read.
    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertDontSee('Sent')
        ->assertDontSee('Seen');

    // Now that the user has viewed it, the admin sees their reply marked "Seen".
    $this->actingAs($admin)
        ->get(route('admin.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Seen');
});

test('all message bubbles use a dark background in both light and dark mode', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'User message']);
    $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin message']);

    foreach ([$user, $admin] as $viewer) {
        $route = $viewer->is_admin ? route('admin.tickets.show', $ticket) : route('support.tickets.show', $ticket);
        $html = $this->actingAs($viewer)->get($route)->assertOk()->getContent();

        preg_match('/class="([^"]*)">\s*User message/', $html, $userBubble);
        preg_match('/class="([^"]*)">\s*Admin message/', $html, $adminBubble);

        // Always dark bg, no dark: variant flipping to light
        expect($userBubble[1])->toContain('bg-zinc-800 text-white')
            ->and($userBubble[1])->not->toContain('dark:bg-zinc-100')
            ->and($adminBubble[1])->toContain('bg-zinc-800 text-white')
            ->and($adminBubble[1])->not->toContain('dark:bg-zinc-100');
    }
});

test('a sent attachment renders with an expand button and full-size modal', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create([
        'user_id' => $user->id,
        'body' => 'See attached',
        'image_path' => UploadedFile::fake()->image('screenshot.png')->store('support-tickets', 'public'),
    ]);

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('View full image')
        ->assertSee('data-flux-modal', false);
});

test('a user can close their own ticket but cannot reopen it', function () {
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($user)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('toggleStatus');

    expect($ticket->refresh()->status)->toBe(SupportTicket::STATUS_CLOSED);

    // Reopening is a no-op for the ticket owner.
    Livewire::actingAs($user)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('toggleStatus');

    expect($ticket->refresh()->status)->toBe(SupportTicket::STATUS_CLOSED);
});

test('a closed ticket has no reopen button for the user, but admin can reopen it', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'closed', 'last_message_at' => now()]);

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertDontSee('Reopen ticket');

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('toggleStatus');

    expect($ticket->refresh()->status)->toBe(SupportTicket::STATUS_OPEN);
});

test('a closed ticket prompts the user with a link to open a new ticket', function () {
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'closed', 'last_message_at' => now()]);

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee(route('support.tickets.create'), false)
        ->assertSee('Open a new ticket', false);
});

test('the new ticket page renders the composer for a user with no open ticket', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('support.tickets.create'))
        ->assertOk()
        ->assertSee('Submit ticket')
        ->assertSee('Please include as much detail as you can')
        ->assertSee('We’ll do our best to reply within 24 hours');
});

test('a user sees the composer and an admin sees the rich editor on the same ticket thread', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'First message']);

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('data-flux-composer', false);

    $this->actingAs($admin)
        ->get(route('admin.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('data-flux-editor', false);
});

test('the support page always shows the My Tickets tab', function () {
    $user = User::factory()->create();
    SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    $this->actingAs($user)
        ->get(route('support'))
        ->assertOk()
        ->assertSee('Help')
        ->assertSee('My Tickets');
});

test('selecting the My Tickets tab does not redirect when the user has no tickets', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SupportCenter::class)
        ->assertSee('My Tickets')
        ->set('tab', 'tickets')
        ->assertNoRedirect()
        ->assertSee('New ticket')
        ->assertSee(route('support.tickets.create'), false);
});

test('selecting the My Tickets tab shows closed tickets without redirect and offers a new ticket link', function () {
    $user = User::factory()->create();
    SupportTicket::create(['user_id' => $user->id, 'subject' => 'Old ticket', 'status' => 'closed', 'last_message_at' => now()]);

    Livewire::actingAs($user)
        ->test(SupportCenter::class)
        ->assertSee('My Tickets')
        ->set('tab', 'tickets')
        ->assertNoRedirect()
        ->assertSee('Old ticket')
        ->assertSee('New ticket')
        ->assertSee('Ticket closed ·')
        ->assertSee(route('support.tickets.create'), false);
});

test('selecting the My Tickets tab shows the ticket list with status time labels', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    $closedTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Old ticket', 'status' => 'closed', 'last_message_at' => now()]);
    $closedTicket->messages()->create(['user_id' => $user->id, 'body' => 'Bye']);

    $supportAnsweredTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Support answered', 'status' => 'open', 'last_message_at' => now()->addMinute()]);
    $supportAnsweredTicket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Reply']);

    $newTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'New ticket', 'status' => 'open', 'last_message_at' => now()->addMinutes(2)]);
    $newTicket->messages()->create(['user_id' => $user->id, 'body' => 'Question']);

    $userRepliedTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'User replied', 'status' => 'open', 'last_message_at' => now()->addMinutes(3)]);
    $userRepliedTicket->messages()->create(['user_id' => $user->id, 'body' => 'First']);
    $userRepliedTicket->messages()->create(['user_id' => $user->id, 'body' => 'Follow-up']);

    Livewire::actingAs($user)
        ->test(SupportCenter::class)
        ->assertSee('My Tickets')
        ->set('tab', 'tickets')
        ->assertNoRedirect()
        ->assertSee('Old ticket')
        ->assertSee('Support answered')
        ->assertSee('New ticket')
        ->assertSee('User replied')
        ->assertSee('Ticket closed ·')
        ->assertSee('Support replied ·')
        ->assertSee('Ticket created ·')
        ->assertSee('You replied ·');
});

test('the support tab can be preselected via the URL', function () {
    $user = User::factory()->create();
    SupportTicket::create(['user_id' => $user->id, 'subject' => 'Open ticket', 'status' => 'open', 'last_message_at' => now()]);

    $this->actingAs($user)
        ->get(route('support', ['tab' => 'tickets']))
        ->assertOk()
        ->assertSee('Open ticket');
});

test('the account dropdown links straight to the My Tickets tab and shows an unread count when there is an open ticket', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'We are on it']);

    $html = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('support', ['tab' => 'tickets']))
        ->getContent();

    expect(preg_match('/data-flux-badge[^>]*>\s*1\s*</', $html))->toBe(1);
});

test('admin sees only open tickets', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    SupportTicket::create(['user_id' => $user->id, 'subject' => 'Open ticket', 'status' => 'open', 'last_message_at' => now()]);
    SupportTicket::create(['user_id' => $user->id, 'subject' => 'Closed ticket', 'status' => 'closed', 'last_message_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.tickets'))
        ->assertOk()
        ->assertSee('Open ticket')
        ->assertDontSee('Closed ticket');
});

test('open tickets are sorted with user replies before admin replies', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    $adminRepliedTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Admin replied', 'status' => 'open', 'last_message_at' => now()->addHour()]);
    $adminRepliedTicket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply']);

    $userRepliedTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'User replied', 'status' => 'open', 'last_message_at' => now()]);
    $userRepliedTicket->messages()->create(['user_id' => $user->id, 'body' => 'User follow-up']);

    Livewire::actingAs($admin)
        ->test(TicketManager::class)
        ->assertSeeInOrder(['User replied', 'Admin replied']);
});

test('the admin ticket list shows the correct status badge and time label for each ticket', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    $needsReplyTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Needs reply', 'status' => 'open', 'last_message_at' => now()]);
    $needsReplyTicket->messages()->create(['user_id' => $user->id, 'body' => 'User question']);

    $unseenTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Unseen', 'status' => 'open', 'last_message_at' => now()->addMinute()]);
    $unseenTicket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply', 'read_at' => null]);

    $seenTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Seen', 'status' => 'open', 'last_message_at' => now()->addHour()]);
    $seenTicket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Read reply', 'read_at' => now()]);

    Livewire::actingAs($admin)
        ->test(TicketManager::class)
        ->assertSee('Needs reply')
        ->assertSee('Unseen')
        ->assertSee('Seen')
        ->assertSee('User replied ·')
        ->assertSee('Support replied ·')
        ->assertSee('Seen ·');
});

test('an admin can close a ticket directly from the ticket list', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Close me', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'Help']);

    Livewire::actingAs($admin)
        ->test(TicketManager::class)
        ->call('closeTicket', $ticket->id)
        ->assertHasNoErrors();

    expect($ticket->refresh()->status)->toBe(SupportTicket::STATUS_CLOSED);
});

test('the support center never shows an open badge and labels tickets for the user', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    $waitingTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Waiting', 'status' => 'open', 'last_message_at' => now()]);
    $waitingTicket->messages()->create(['user_id' => $user->id, 'body' => 'Help']);

    $supportRepliedTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Support Replied', 'status' => 'open', 'last_message_at' => now()->addMinute()]);
    $supportRepliedTicket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Reply']);

    $closedTicket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Closed', 'status' => 'closed', 'last_message_at' => now()->addMinutes(2)]);
    $closedTicket->messages()->create(['user_id' => $user->id, 'body' => 'Bye']);

    Livewire::actingAs($user)
        ->test(SupportCenter::class, ['tab' => 'tickets'])
        ->assertDontSeeText('Open')
        ->assertSee('In Review')
        ->assertSee('Support Replied')
        ->assertSee('Closed');
});

// --- Admin edit / delete unread messages ---

test('an admin can edit their own last message in a ticket', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'User message']);
    $adminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Original reply']);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('startEditing', $adminMessage->id)
        ->assertSet('editingMessageId', $adminMessage->id)
        ->assertSet('editBody', 'Original reply')
        ->set('editBody', 'Updated reply')
        ->call('saveEdit')
        ->assertSet('editingMessageId', null);

    expect($adminMessage->refresh()->body)->toBe('Updated reply');
});

test('an admin can edit any of their own unread messages, not only the last one', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $firstAdminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'First admin reply']);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'User follow-up']);
    $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Second admin reply']);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('startEditing', $firstAdminMessage->id)
        ->assertSet('editingMessageId', $firstAdminMessage->id)
        ->assertSet('editBody', 'First admin reply')
        ->set('editBody', 'Updated first reply')
        ->call('saveEdit')
        ->assertSet('editingMessageId', null);

    expect($firstAdminMessage->refresh()->body)->toBe('Updated first reply');
});

test('an admin cannot edit a message that has already been seen by the user', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $adminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply', 'read_at' => now()]);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('startEditing', $adminMessage->id)
        ->assertSet('editingMessageId', null);

    expect($adminMessage->refresh()->body)->toBe('Admin reply');
});

test('a regular user cannot edit admin messages', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $adminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply']);

    Livewire::actingAs($user)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('startEditing', $adminMessage->id)
        ->assertSet('editingMessageId', null);
});

test('an admin can delete their own last message in a ticket', function () {
    Notification::fake();

    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'User message']);
    $adminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply to delete']);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('deleteMessage', $adminMessage->id)
        ->assertHasNoErrors();

    expect($ticket->messages()->where('id', $adminMessage->id)->exists())->toBeFalse()
        ->and($ticket->messages()->count())->toBe(1);
});

test('an admin can delete any of their own unread messages, not only the last one', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $firstAdminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'First admin reply']);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'User follow-up']);
    $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Second admin reply']);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('deleteMessage', $firstAdminMessage->id)
        ->assertHasNoErrors();

    expect($ticket->messages()->where('id', $firstAdminMessage->id)->exists())->toBeFalse();
});

test('an admin cannot delete a message that has already been seen by the user', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $adminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply', 'read_at' => now()]);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('deleteMessage', $adminMessage->id)
        ->assertHasNoErrors();

    expect($ticket->messages()->where('id', $adminMessage->id)->exists())->toBeTrue();
});

test('a regular user cannot delete admin messages', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $adminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply']);

    Livewire::actingAs($user)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('deleteMessage', $adminMessage->id)
        ->assertHasNoErrors();

    expect($ticket->messages()->where('id', $adminMessage->id)->exists())->toBeTrue();
});

test('edit body validation requires a non-empty string', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $adminMessage = $ticket->messages()->create(['user_id' => $admin->id, 'author_name' => 'Support', 'body' => 'Admin reply']);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->call('startEditing', $adminMessage->id)
        ->set('editBody', '')
        ->call('saveEdit')
        ->assertHasErrors(['editBody']);

    expect($adminMessage->refresh()->body)->toBe('Admin reply');
});
