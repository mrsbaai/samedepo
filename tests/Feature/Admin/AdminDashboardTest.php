<?php

use App\Livewire\Dashboard\AdminDashboard;
use App\Models\Deposit;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Security\Models\SecurityBlock;
use App\Security\Models\ThreatEvent;
use Livewire\Livewire;

test('an admin can view the admin overview', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Overview');
});

test('owners cannot access the admin overview', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('guests are redirected to signin from the admin overview', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('signin'));
});

test('open support tickets section is hidden when there are no open tickets', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('Open support tickets')
        ->assertDontSee('All caught up');
});

test('open support tickets are shown first with the same cards as the ticket manager', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'email' => 'owner@example.com']);
    $ticket = SupportTicket::create([
        'user_id' => $owner->id,
        'subject' => 'Payment not credited',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now(),
    ]);
    SupportTicketMessage::create([
        'support_ticket_id' => $ticket->id,
        'user_id' => $owner->id,
        'body' => 'My customer sent BTC but it is not showing.',
        'read_at' => null,
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();

    $response->assertSee('Open support tickets');
    $response->assertSee('Payment not credited');
    $response->assertSee('owner@example.com');
    $response->assertSee('Needs reply');
    $response->assertSee('View all tickets');
});

test('a ticket can be closed from the overview', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $ticket = SupportTicket::create([
        'user_id' => $owner->id,
        'subject' => 'Closing test',
        'status' => SupportTicket::STATUS_OPEN,
        'last_message_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test(AdminDashboard::class)
        ->call('closeTicket', $ticket->id)
        ->assertHasNoErrors();

    expect($ticket->fresh()->status)->toBe(SupportTicket::STATUS_CLOSED);
});

test('platform status aggregates are displayed', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);

    UsdValuation::create(['network' => 'bitcoin', 'conversion_value' => 60000]);
    UsdValuation::create(['network' => 'usdt_trc20', 'conversion_value' => 1]);

    Deposit::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => 0.5,
        'status' => 'credited',
        'credited_at' => now()->subHours(2),
    ]);

    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => 0.1,
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Platform status')
        ->assertSee('Owners')
        ->assertSee('Deposits (24h)')
        ->assertSee('Pending withdrawals')
        ->assertSee('$30,000.00'); // 0.5 BTC @ $60,000
});

test('security summary is hidden when there are no recent threats or blocks', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('Security summary')
        ->assertDontSee('Active attack')
        ->assertDontSee('No recent threats');
});

test('security summary reflects an active attack status', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    foreach (range(1, 5) as $i) {
        ThreatEvent::create([
            'detector' => 'test',
            'threat_type' => 'brute_force',
            'severity' => 5,
            'description' => 'Brute force attempt',
            'method' => 'GET',
            'path' => '/',
            'ip_address' => "10.0.0.{$i}",
        ]);
    }

    SecurityBlock::create(['type' => SecurityBlock::TYPE_IP, 'value' => '10.0.0.1']);
    SecurityBlock::create(['type' => SecurityBlock::TYPE_DEVICE, 'value' => 'abc123']);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Active attack')
        ->assertSee('Events (1h)')
        ->assertSee('Blocked IPs')
        ->assertSee('Blocked devices');
});

test('security summary reflects an elevated status', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    ThreatEvent::create([
        'detector' => 'test',
        'threat_type' => 'scan',
        'severity' => 4,
        'description' => 'Port scan',
        'method' => 'GET',
        'path' => '/',
        'ip_address' => '10.0.0.1',
    ]);
    ThreatEvent::create([
        'detector' => 'test',
        'threat_type' => 'scan',
        'severity' => 4,
        'description' => 'Port scan',
        'method' => 'GET',
        'path' => '/',
        'ip_address' => '10.0.0.2',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Elevated')
        ->assertDontSee('Active attack');
});
