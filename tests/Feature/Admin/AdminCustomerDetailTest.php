<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
});

function adminCustomerFixture(): array
{
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'is_admin' => false]);
    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'customer-1']);

    $btc = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    $trc = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $erc = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20']);

    $deposits = [
        ['status' => 'detected', 'gross' => '1.00000000', 'offset' => 4],
        ['status' => 'pending', 'gross' => '2.00000000', 'offset' => 3],
        ['status' => 'credited', 'gross' => '3.00000000', 'offset' => 2, 'fee' => '0.06000000', 'credited' => '2.94000000'],
        ['status' => 'ignored', 'gross' => '0.50000000', 'offset' => 1],
    ];

    foreach ($deposits as $data) {
        Deposit::create([
            'deposit_address_id' => $trc->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'network' => 'usdt_trc20',
            'tx_hash' => 'tx-'.$data['status'],
            'gross_amount' => $data['gross'],
            'fee_amount' => $data['fee'] ?? null,
            'credited_amount' => $data['credited'] ?? null,
            'confirmation_count' => 0,
            'status' => $data['status'],
            'detected_at' => now()->subHours($data['offset']),
            'credited_at' => $data['status'] === 'credited' ? now()->subHours($data['offset']) : null,
        ]);
    }

    return ['admin' => $admin, 'owner' => $owner, 'customer' => $customer, 'btc' => $btc, 'trc' => $trc, 'erc' => $erc];
}

test('admin sees customer reference addresses and all deposit statuses', function () {
    $f = adminCustomerFixture();

    $response = $this->actingAs($f['admin'])
        ->get(route('admin.owners.customers.show', [$f['owner'], $f['customer']->customer_reference]))
        ->assertOk();

    $response
        ->assertSee($f['customer']->customer_reference, false)
        ->assertSee($f['btc']->address, false)
        ->assertSee($f['trc']->address, false)
        ->assertSee($f['erc']->address, false)
        ->assertSee('Bitcoin', false)
        ->assertSee('USDT (TRC20)', false)
        ->assertSee('USDT (ERC20)', false)
        ->assertSee('Detected', false)
        ->assertSee('Pending', false)
        ->assertSee('Credited', false)
        ->assertSee('Ignored', false);
});

test('deposits paginate at fifteen per page', function () {
    $f = adminCustomerFixture();

    for ($i = 1; $i <= 12; $i++) {
        Deposit::create([
            'deposit_address_id' => $f['trc']->id,
            'customer_id' => $f['customer']->id,
            'user_id' => $f['owner']->id,
            'network' => 'usdt_trc20',
            'tx_hash' => 'tx-extra-'.$i,
            'gross_amount' => '1.00000000',
            'fee_amount' => null,
            'credited_amount' => null,
            'confirmation_count' => 0,
            'status' => 'credited',
            'detected_at' => now()->subDays($i + 10),
            'credited_at' => now()->subDays($i + 10),
        ]);
    }

    $this->actingAs($f['admin'])
        ->get(route('admin.owners.customers.show', [$f['owner'], $f['customer']->customer_reference]))
        ->assertOk()
        ->assertSee('tx-extra-1', false)
        ->assertDontSee('tx-extra-12', false);

    $this->actingAs($f['admin'])
        ->get(route('admin.owners.customers.show', [$f['owner'], $f['customer']->customer_reference]).'?page=2')
        ->assertOk()
        ->assertSee('tx-extra-12', false);
});

test('transaction explorer link contains the tx hash', function () {
    $f = adminCustomerFixture();

    $this->actingAs($f['admin'])
        ->get(route('admin.owners.customers.show', [$f['owner'], $f['customer']->customer_reference]))
        ->assertOk()
        ->assertSee('tronscan.org/#/transaction/tx-credited', false);
});

test('owner role cannot access admin customer detail', function () {
    $f = adminCustomerFixture();
    $otherOwner = User::factory()->create(['role' => 'owner', 'is_admin' => false]);

    $this->actingAs($otherOwner)
        ->get(route('admin.owners.customers.show', [$f['owner'], $f['customer']->customer_reference]))
        ->assertForbidden();
});

test('a customer reference under a different owner is not found', function () {
    $f = adminCustomerFixture();
    $otherOwner = User::factory()->create(['role' => 'owner', 'is_admin' => false]);

    $this->actingAs($f['admin'])
        ->get(route('admin.owners.customers.show', [$otherOwner, $f['customer']->customer_reference]))
        ->assertOk()
        ->assertSee('Customer not found', false);
});

test('an unknown customer reference is not found', function () {
    $f = adminCustomerFixture();

    $this->actingAs($f['admin'])
        ->get(route('admin.owners.customers.show', [$f['owner'], 'missing-ref']))
        ->assertOk()
        ->assertSee('Customer not found', false);
});

test('a non-owner id as owner parameter is not found', function () {
    $f = adminCustomerFixture();

    $this->actingAs($f['admin'])
        ->get('/admin/owners/'.$f['admin']->id.'/customers/'.$f['customer']->customer_reference)
        ->assertOk()
        ->assertSee('Customer not found', false);
});
