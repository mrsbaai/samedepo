<?php

use App\Livewire\Dashboard\AdminDashboard;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasPolicy;
use App\Models\PlatformSettings;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Security\Models\SecurityBlock;
use App\Security\Models\ThreatEvent;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Livewire\Livewire;

function adminDashboardProfitFixture(
    string $network = 'usdt_trc20',
    string $ownerBalance = '90.00000000',
    string $pendingWithdrawal = '20.00000000',
    string $paidOut = '5.00000000',
    string $unswept = '40.00000000',
    string $available = '100.00000000',
    ?string $profitAddress = 'T111111111111111111111111111111111',
    string $nativeBalance = '50.00000000',
    string $reserveThreshold = '1.00000000',
    ?DateTimeInterface $refreshedAt = null,
): array {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    $addresses = [
        'profit_address_bitcoin' => '1BoatSLRHtKNngkdXEeobR76b53LETtpyT',
        'profit_address_usdt_trc20' => 'T111111111111111111111111111111111',
        'profit_address_usdt_erc20' => '0x1111111111111111111111111111111111111111',
    ];
    if ($profitAddress === null) {
        $addresses['profit_address_usdt_trc20'] = null;
    }
    PlatformSettings::instance()->update($addresses);

    $wallet = TreasuryWallet::factory()->create([
        'network' => $network,
        'derivation_index' => 0,
        'address' => "treasury-$network",
        'available_funds' => $available,
        'native_balance' => $network === 'bitcoin' ? '0.50000000' : $nativeBalance,
        'refreshed_at' => $refreshedAt ?? now(),
    ]);

    if ($network !== 'bitcoin') {
        GasPolicy::factory()->create(['network' => $network, 'reserve_threshold' => $reserveThreshold]);
    }

    if (bccomp($ownerBalance, '0', 8) > 0) {
        Balance::factory()->create([
            'user_id' => $owner->id,
            'network' => $network,
            'amount' => $ownerBalance,
        ]);
    }

    if (bccomp($pendingWithdrawal, '0', 8) > 0) {
        Withdrawal::factory()->create([
            'user_id' => $owner->id,
            'network' => $network,
            'gross_amount' => $pendingWithdrawal,
            'status' => 'pending',
        ]);
    }

    if (bccomp($unswept, '0', 8) > 0) {
        $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => $network]);
        Deposit::create([
            'deposit_address_id' => $address->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'network' => $network,
            'tx_hash' => 'tx-'.uniqid(),
            'gross_amount' => $unswept,
            'status' => 'credited',
            'detected_at' => now(),
            'credited_at' => now(),
            'swept_at' => null,
        ]);
    }

    if (bccomp($paidOut, '0', 8) > 0) {
        TreasuryPayout::create([
            'network' => $network,
            'destination_address' => 'T111111111111111111111111111111111',
            'amount' => $paidOut,
            'status' => 'confirmed',
            'created_by' => $admin->id,
        ]);
    }

    UsdValuation::create(['network' => $network, 'conversion_value' => '1.000000']);

    return [$admin, $wallet];
}

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

test('treasury card renders between platform status and security summary', function () {
    [$admin] = adminDashboardProfitFixture();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Treasury')
        ->assertSee('Withdrawable profit')
        ->assertSee('Total profit')
        ->assertSee('Unswept funds')
        ->assertSee('Gas float')
        ->assertSee('Failed ops (24h)')
        ->assertSee('$30.00');
});

test('treasury status is healthy with clean fixture', function () {
    [$admin] = adminDashboardProfitFixture();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Healthy');
});

test('treasury status shows attention when gas is low', function () {
    [$admin] = adminDashboardProfitFixture(reserveThreshold: '100.00000000', nativeBalance: '50.00000000');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Attention')
        ->assertSee('Low gas');
});

test('treasury status shows attention on missing profit address', function () {
    [$admin] = adminDashboardProfitFixture(profitAddress: null);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Attention')
        ->assertSee('Set payout address');
});

test('treasury status shows deficit when liabilities exceed assets', function () {
    [$admin] = adminDashboardProfitFixture(ownerBalance: '150.00000000');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Deficit');
});

test('treasury status shows attention on failed operations in the last 24 hours', function () {
    [$admin] = adminDashboardProfitFixture();
    TreasurySweep::create([
        'network' => 'usdt_trc20',
        'treasury_wallet_id' => 1,
        'tx_hash' => 'failed-sweep',
        'amount' => '10.00000000',
        'status' => 'failed',
        'error_message' => 'Sweep failed',
        'updated_at' => now()->subHour(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Attention')
        ->assertSee('1');
});

test('withdraw profit deep link targets the highest withdrawable network with an address', function () {
    [$admin] = adminDashboardProfitFixture();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('admin.treasury', ['payout' => 'usdt_trc20']));
});

test('withdraw profit button is disabled when nothing is withdrawable', function () {
    [$admin] = adminDashboardProfitFixture(unswept: '0.00000000', ownerBalance: '120.00000000', pendingWithdrawal: '0.00000000', available: '100.00000000');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Withdraw profit');
});

test('refresh treasury data refreshes stale wallets once and preserves values on provider failure', function () {
    [$admin, $wallet] = adminDashboardProfitFixture(refreshedAt: now()->subMinutes(5));
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('getNativeBalance')->once()->andReturn(null);
    $broadcaster->shouldReceive('getTronResource')->andReturn(null);
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($admin)
        ->test(AdminDashboard::class)
        ->call('refreshTreasuryData');

    expect($wallet->refresh()->native_balance)->toBe('50.00000000');
});

test('refresh treasury data does not call broadcaster while cache lock is held', function () {
    [$admin, $wallet] = adminDashboardProfitFixture(refreshedAt: now()->subMinutes(5));
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('getNativeBalance')->once()->andReturn('999.00000000');
    $broadcaster->shouldReceive('getTronResource')->andReturn(null);
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($admin)
        ->test(AdminDashboard::class)
        ->call('refreshTreasuryData');

    Livewire::actingAs($admin)
        ->test(AdminDashboard::class)
        ->call('refreshTreasuryData');

    expect($wallet->refresh()->native_balance)->toBe('999.00000000');
});
