<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;

test('a customer belongs to a website owner', function () {
    $owner = User::factory()->create();
    $customer = Customer::create([
        'user_id' => $owner->id,
        'customer_reference' => 'CUST-001',
    ]);

    expect($customer->user->id)->toBe($owner->id);
});

test('a customer has deposit addresses and deposits', function () {
    $customer = Customer::factory()->create();
    DepositAddress::factory()->for($customer)->create(['network' => 'bitcoin']);
    Deposit::factory()->for($customer->depositAddresses->first())->for($customer)->create();

    expect($customer->depositAddresses)->toHaveCount(1)
        ->and($customer->deposits)->toHaveCount(1);
});

test('customers are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    Customer::create([
        'user_id' => $owner->id,
        'customer_reference' => 'OWNER-CUST',
    ]);
    Customer::create([
        'user_id' => $otherOwner->id,
        'customer_reference' => 'OTHER-CUST',
    ]);

    $this->actingAs($owner);

    expect(Customer::pluck('customer_reference')->toArray())->toBe(['OWNER-CUST']);
});
