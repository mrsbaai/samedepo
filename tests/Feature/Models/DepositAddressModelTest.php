<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;

test('a deposit address belongs to a customer', function () {
    $customer = Customer::factory()->create();
    $address = DepositAddress::factory()->for($customer)->create([
        'network' => 'bitcoin',
        'address' => 'btc-placeholder-address',
    ]);

    expect($address->customer->id)->toBe($customer->id);
});

test('a deposit address can have many deposits', function () {
    $address = DepositAddress::factory()->create();
    Deposit::factory()->for($address)->for($address->customer)->create();

    expect($address->deposits)->toHaveCount(1);
});

test('deposit addresses are scoped to the authenticated owner through the customer', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    $ownerCustomer = Customer::create([
        'user_id' => $owner->id,
        'customer_reference' => 'OWNER-CUST',
    ]);
    $otherCustomer = Customer::create([
        'user_id' => $otherOwner->id,
        'customer_reference' => 'OTHER-CUST',
    ]);

    DepositAddress::create([
        'customer_id' => $ownerCustomer->id,
        'network' => 'bitcoin',
        'address' => 'owner-address',
    ]);
    DepositAddress::create([
        'customer_id' => $otherCustomer->id,
        'network' => 'bitcoin',
        'address' => 'other-address',
    ]);

    $this->actingAs($owner);

    expect(DepositAddress::pluck('address')->toArray())->toBe(['owner-address']);
});
