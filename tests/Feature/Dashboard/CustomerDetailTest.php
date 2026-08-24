<?php

use App\Models\Customer;
use App\Models\DepositAddress;
use App\Models\User;

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
