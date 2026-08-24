<?php

use App\Livewire\Dashboard\UserDashboard;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use Livewire\Livewire;

function seedBalances(User $owner): void
{
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'bitcoin', 'amount' => '0.50000000']);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => '100.00000000']);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'usdt_erc20', 'amount' => '50.00000000']);

    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => 60000]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => 1]);
    UsdValuation::factory()->create(['network' => 'usdt_erc20', 'conversion_value' => 1]);
}

test('an authenticated owner can access the dashboard home', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Home', false);
});

test('balance cards and total usd display correctly formatted values', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    seedBalances($owner);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('$30150.00', false)
        ->assertSee('0.50000000 BTC', false)
        ->assertSee('100.00 USDT', false)
        ->assertSee('50.00 USDT', false);
});

test('recent activity table renders deposits and withdrawals', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    seedBalances($owner);

    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-TEST']);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'credited_amount' => '0.00100000',
        'status' => 'credited',
        'detected_at' => now(),
    ]);

    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount_sent' => '25.00',
        'status' => 'sent',
        'created_at' => now()->subHour(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('CUST-TEST', false)
        ->assertSee('0.00100000 BTC', false)
        ->assertSee('Credited', false)
        ->assertSee('25.00 USDT', false)
        ->assertSee('Sent', false);
});

test('empty state is shown when there is no activity', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    seedBalances($owner);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Nothing here yet. Once a customer sends Bitcoin, USDT (TRC20), or USDT (ERC20) to one of their deposit addresses, it shows up here the moment we detect it.', false);
});

test('error state renders a callout and retry resets to normal', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    seedBalances($owner);

    $this->actingAs($owner)
        ->get(route('dashboard', ['state' => 'error']))
        ->assertOk()
        ->assertSeeText("Couldn't load dashboard data");

    Livewire::actingAs($owner)
        ->test(UserDashboard::class)
        ->set('uiState', 'error')
        ->assertSeeText("Couldn't load dashboard data")
        ->call('retry')
        ->assertSet('uiState', 'normal');
});

test('period and network filters change the displayed activity', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    seedBalances($owner);

    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-RECENT']);
    $recentAddress = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);

    Deposit::factory()->create([
        'deposit_address_id' => $recentAddress->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'credited_amount' => '0.00200000',
        'status' => 'credited',
        'detected_at' => now()->subDays(2),
    ]);

    $oldCustomer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-OLD']);
    $oldAddress = DepositAddress::factory()->create(['customer_id' => $oldCustomer->id, 'network' => 'usdt_trc20']);

    Deposit::factory()->create([
        'deposit_address_id' => $oldAddress->id,
        'customer_id' => $oldCustomer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'credited_amount' => '75.00',
        'status' => 'credited',
        'detected_at' => now()->subDays(45),
    ]);

    Livewire::actingAs($owner)
        ->test(UserDashboard::class)
        ->set('period', '7')
        ->assertSee('CUST-RECENT', false)
        ->assertDontSee('CUST-OLD', false)
        ->set('period', '90')
        ->assertSee('CUST-RECENT', false)
        ->assertSee('CUST-OLD', false)
        ->set('networkFilter', 'bitcoin')
        ->assertSee('CUST-RECENT', false)
        ->assertDontSee('CUST-OLD', false);
});

test('only owners can view the dashboard, admins are rejected', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertForbidden();
});
