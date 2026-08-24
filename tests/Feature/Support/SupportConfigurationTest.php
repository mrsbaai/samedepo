<?php

use App\Models\Faq;
use App\Models\SupportTicket;
use App\Models\User;

test('owners see a support link in the main dashboard navigation', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $response = $this->actingAs($owner)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Support');
    $response->assertSee(route('support'));
});

test('admins do not see the owner support link in the main nav', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertOk();
    $response->assertDontSee('href="'.route('support').'"', false);
});

test('faqs are shown on the ticket creation page', function () {
    Faq::create(['question' => 'What is samedepo?', 'answer' => 'A crypto deposit address service.', 'position' => 1]);
    $owner = User::factory()->create(['role' => 'owner']);

    $response = $this->actingAs($owner)->get(route('support.tickets.create'));

    $response->assertOk();
    $response->assertSee('What is samedepo?');
    $response->assertSee('A crypto deposit address service.');
});

test('an owner can only see their own tickets in the support center', function () {
    $ownerA = User::factory()->create(['role' => 'owner']);
    $ownerB = User::factory()->create(['role' => 'owner']);

    SupportTicket::create([
        'user_id' => $ownerA->id,
        'subject' => 'Owner A ticket',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
    SupportTicket::create([
        'user_id' => $ownerB->id,
        'subject' => 'Owner B ticket',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $response = $this->actingAs($ownerA)->get(route('support', ['tab' => 'tickets']));

    $response->assertOk();
    $response->assertSee('Owner A ticket');
    $response->assertDontSee('Owner B ticket');
});

test('an admin sees all open tickets in the admin ticket manager', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $ownerA = User::factory()->create(['role' => 'owner']);
    $ownerB = User::factory()->create(['role' => 'owner']);

    SupportTicket::create([
        'user_id' => $ownerA->id,
        'subject' => 'Owner A ticket',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
    SupportTicket::create([
        'user_id' => $ownerB->id,
        'subject' => 'Owner B ticket',
        'status' => SupportTicket::STATUS_CLOSED,
        'last_message_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.tickets'))
        ->assertOk()
        ->assertSee('Owner A ticket')
        ->assertDontSee('Owner B ticket');
});

test('closing a ticket shows the confirmation copy from content-rules', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $ticket = SupportTicket::create([
        'user_id' => $owner->id,
        'subject' => 'Close me',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get(route('support.tickets.show', $ticket));

    $response->assertOk();
    $response->assertSee('Close ticket');
    $response->assertSee('This ends the conversation. Reopen by asking the website owner to create a new ticket.');
});
