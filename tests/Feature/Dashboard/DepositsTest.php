<?php

use App\Livewire\Dashboard\Deposits;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use Livewire\Livewire;

function makeDeposit(User $owner, array $attributes = []): Deposit
{
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => $attributes['network'] ?? 'bitcoin',
    ]);

    return Deposit::factory()->create(array_merge([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $address->network,
        'status' => 'detected',
        'detected_at' => now(),
    ], $attributes));
}

test('an owner can view their deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-DEP1']);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);

    makeDeposit($owner, [
        'customer_id' => $customer->id,
        'deposit_address_id' => $address->id,
        'network' => 'bitcoin',
        'status' => 'credited',
        'gross_amount' => '0.12345678',
        'detected_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('deposits'))
        ->assertOk()
        ->assertSee('Deposits', false)
        ->assertSee('CUST-DEP1', false)
        ->assertSee('0.12345678 BTC', false)
        ->assertSee('Credited', false);
});

test('an owner only sees their own deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $otherOwner = User::factory()->create(['role' => 'owner']);

    $mine = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-MINE']);
    $mineAddress = DepositAddress::factory()->create(['customer_id' => $mine->id, 'network' => 'bitcoin']);
    makeDeposit($owner, ['customer_id' => $mine->id, 'deposit_address_id' => $mineAddress->id, 'status' => 'detected']);

    $theirs = Customer::factory()->create(['user_id' => $otherOwner->id, 'customer_reference' => 'CUST-THEIRS']);
    $theirAddress = DepositAddress::factory()->create(['customer_id' => $theirs->id, 'network' => 'bitcoin']);
    makeDeposit($otherOwner, ['customer_id' => $theirs->id, 'deposit_address_id' => $theirAddress->id, 'status' => 'detected']);

    $this->actingAs($owner)
        ->get(route('deposits'))
        ->assertOk()
        ->assertSee('CUST-MINE', false)
        ->assertDontSee('CUST-THEIRS', false);
});

test('status filter shows only matching deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $pendingCustomer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-PEND']);
    $pendingAddress = DepositAddress::factory()->create(['customer_id' => $pendingCustomer->id, 'network' => 'bitcoin']);
    makeDeposit($owner, ['customer_id' => $pendingCustomer->id, 'deposit_address_id' => $pendingAddress->id, 'status' => 'pending']);

    $creditedCustomer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-CRED']);
    $creditedAddress = DepositAddress::factory()->create(['customer_id' => $creditedCustomer->id, 'network' => 'bitcoin']);
    makeDeposit($owner, ['customer_id' => $creditedCustomer->id, 'deposit_address_id' => $creditedAddress->id, 'status' => 'credited']);

    Livewire::actingAs($owner)
        ->test(Deposits::class)
        ->assertSee('CUST-PEND', false)
        ->assertSee('CUST-CRED', false)
        ->set('statusFilter', 'pending')
        ->assertSee('CUST-PEND', false)
        ->assertDontSee('CUST-CRED', false)
        ->set('statusFilter', 'credited')
        ->assertDontSee('CUST-PEND', false)
        ->assertSee('CUST-CRED', false);
});

test('ignored deposits are never shown', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-IGNORED']);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    makeDeposit($owner, ['customer_id' => $customer->id, 'deposit_address_id' => $address->id, 'status' => 'ignored']);

    $this->actingAs($owner)
        ->get(route('deposits'))
        ->assertOk()
        ->assertDontSee('CUST-IGNORED', false);
});

test('pagination works across pages of deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    foreach (range(1, 12) as $i) {
        $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => "CUST-PAGE-$i"]);
        $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
        makeDeposit($owner, [
            'customer_id' => $customer->id,
            'deposit_address_id' => $address->id,
            'status' => 'detected',
            'detected_at' => now()->subMinutes($i),
        ]);
    }

    $component = Livewire::actingAs($owner)->test(Deposits::class);

    expect($component->instance()->paginatedDeposits->total())->toBe(12);
    expect($component->instance()->paginatedDeposits->count())->toBe(10);

    $component->call('nextPage')
        ->assertSee('CUST-PAGE-1', false);
});

test('empty state is shown when there are no deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('deposits'))
        ->assertOk()
        ->assertSee('Nothing here yet. Once a customer sends Bitcoin, USDT (TRC20), or USDT (ERC20) to one of their deposit addresses, it shows up here the moment we detect it.', false);
});

test('error state renders a callout and retry resets to normal', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('deposits', ['state' => 'error']))
        ->assertOk()
        ->assertSeeText("Couldn't load deposits");

    Livewire::actingAs($owner)
        ->test(Deposits::class)
        ->set('uiState', 'error')
        ->assertSeeText("Couldn't load deposits")
        ->call('retry')
        ->assertSet('uiState', 'normal');
});

test('tx hash copy action is present for a deposit row', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeDeposit($owner, ['tx_hash' => 'abc123txhash', 'status' => 'detected']);

    $this->actingAs($owner)
        ->get(route('deposits'))
        ->assertOk()
        ->assertSee('navigator.clipboard.writeText', false)
        ->assertSee('abc123txhash', false);
});

test('customer reference links to the customer detail page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-LINK']);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    makeDeposit($owner, ['customer_id' => $customer->id, 'deposit_address_id' => $address->id, 'status' => 'detected']);

    $this->actingAs($owner)
        ->get(route('deposits'))
        ->assertOk()
        ->assertSee(route('customers.show', $customer), false);
});

test('admins cannot access the deposits page', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('deposits'))
        ->assertForbidden();
});
