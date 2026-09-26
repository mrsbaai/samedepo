<?php

use App\Livewire\Dashboard\UserDashboard;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\UsdValuation;
use App\Models\User;
use App\Support\CryptoIcon;
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
        ->assertSee('Dashboard', false);
});

test('balance cards display each network usd value above its crypto amount and logo', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    seedBalances($owner);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('$30,000.00', false)
        ->assertSee('$100.00', false)
        ->assertSee('$50.00', false)
        ->assertSee('0.50000000 BTC', false)
        ->assertSee('100.00 USDT', false)
        ->assertSee('50.00 USDT', false)
        ->assertSee(CryptoIcon::url('btc'), false)
        ->assertSee(CryptoIcon::url('usdt'), false)
        ->assertSee(CryptoIcon::url('trx'), false)
        ->assertSee(CryptoIcon::url('eth'), false)
        ->assertSee(route('withdraw', ['network' => 'bitcoin']), false)
        ->assertSee(route('withdraw', ['network' => 'usdt-trc20']), false)
        ->assertSee(route('withdraw', ['network' => 'usdt-erc20']), false)
        ->assertSee('Withdraw', false)
        ->assertDontSee('$30,150.00', false);
});

test('latest deposits shows the ten newest deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);

    foreach (range(1, 12) as $i) {
        Deposit::factory()->create([
            'deposit_address_id' => $address->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'network' => 'bitcoin',
            'status' => 'credited',
            'credited_amount' => '1.00000000',
            'tx_hash' => 'txhash-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        ]);
    }

    Livewire::actingAs($owner)
        ->test(UserDashboard::class)
        ->assertSee('Latest deposits', false)
        ->assertSee('txhash-12', false)
        ->assertSee('txhash-03', false)
        ->assertDontSee('txhash-02', false)
        ->assertDontSee('txhash-01', false);
});

test('latest deposits shows pending and short-payment statuses and hides other owners deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $other = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    $usdtAddress = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $otherCustomer = Customer::factory()->create(['user_id' => $other->id]);
    $otherAddress = DepositAddress::factory()->create(['customer_id' => $otherCustomer->id, 'network' => 'bitcoin']);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'status' => 'pending',
        'confirmation_count' => 1,
        'tx_hash' => 'pending-tx-hash',
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $usdtAddress->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'below_minimum',
        'gross_amount' => '6.00000000',
        'minimum_amount' => '10.00000000',
        'expires_at' => now()->addDays(5),
        'tx_hash' => 'short-tx-hash',
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $otherAddress->id,
        'customer_id' => $otherCustomer->id,
        'user_id' => $other->id,
        'network' => 'bitcoin',
        'status' => 'credited',
        'tx_hash' => 'other-owner-hash',
    ]);

    Livewire::actingAs($owner)
        ->test(UserDashboard::class)
        ->assertSee('pending-tx-hash', false)
        ->assertSee('Pending · 1/3 confirmations', false)
        ->assertSee('short-tx-hash', false)
        ->assertSee('Below minimum')
        ->assertSee('What to tell your customer')
        ->assertSee('4.00 USDT')
        ->assertDontSee('other-owner-hash', false);
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

test('the deposits page is removed and deposits live in transaction history', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get('/deposits')
        ->assertNotFound();
});

test('only owners can view the dashboard, admins are rejected', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertForbidden();
});
