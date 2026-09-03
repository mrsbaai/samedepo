<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;

test('customer detail routes use the owner supplied reference', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'customer-123']);

    $this->actingAs($owner)
        ->get('/customers/customer-123')
        ->assertOk();

    expect(route('customers.show', 'customer-123'))->toEndWith('/customers/customer-123');
});

test('an authenticated owner can view a customer detail and deposit addresses', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    $bitcoin = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    $usdtTrc20 = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $usdtErc20 = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20']);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertSee($customer->customer_reference, false)
        ->assertSee($bitcoin->address, false)
        ->assertSee($usdtTrc20->address, false)
        ->assertSee($usdtErc20->address, false)
        ->assertSee('Bitcoin', false)
        ->assertSee('USDT (TRC20)', false)
        ->assertSee('USDT (ERC20)', false);
});

test('each deposit address has a copy button', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20']);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertSee('Copy address', false)
        ->assertSee('navigator.clipboard.writeText', false);
});

test('an owner cannot view another owners customer', function () {
    $ownerA = User::factory()->create(['role' => 'owner']);
    $ownerB = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $ownerA->id]);

    $this->actingAs($ownerB)
        ->get(route('customers.show', $customer))
        ->assertForbidden();
});

test('a non-existent customer returns a 404', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get('/customers/999999')
        ->assertNotFound();
});

test('admin users cannot access the customer detail page', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($admin)
        ->get(route('customers.show', $customer))
        ->assertForbidden();
});

test('owner sees detected and pending deposits but not ignored deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);

    Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-detected',
        'gross_amount' => '1.00000000',
        'fee_amount' => null,
        'credited_amount' => null,
        'confirmation_count' => 0,
        'status' => 'detected',
        'detected_at' => now()->subHour(),
        'credited_at' => null,
    ]);

    Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-pending',
        'gross_amount' => '2.00000000',
        'fee_amount' => null,
        'credited_amount' => null,
        'confirmation_count' => 0,
        'status' => 'pending',
        'detected_at' => now()->subHour(),
        'credited_at' => null,
    ]);

    Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-ignored',
        'gross_amount' => '0.50000000',
        'fee_amount' => null,
        'credited_amount' => null,
        'confirmation_count' => 0,
        'status' => 'ignored',
        'detected_at' => now()->subHour(),
        'credited_at' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertSee('tx-detected', false)
        ->assertSee('tx-pending', false)
        ->assertDontSee('tx-ignored', false);
});

test('owner sees credited deposits with fee and credited amounts', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);

    Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-credited',
        'gross_amount' => '10.00000000',
        'fee_amount' => '0.20000000',
        'credited_amount' => '9.80000000',
        'confirmation_count' => 6,
        'status' => 'credited',
        'detected_at' => now()->subHour(),
        'credited_at' => now()->subHour(),
    ]);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertSee('tx-credited', false)
        ->assertSee('10.00 USDT', false)
        ->assertSee('0.20', false)
        ->assertSee('9.80', false);
});
