<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;

test('a deposit belongs to an address, customer and owner', function () {
    $deposit = Deposit::factory()->create();

    expect($deposit->depositAddress)->toBeInstanceOf(DepositAddress::class)
        ->and($deposit->customer)->toBeInstanceOf(Customer::class)
        ->and($deposit->user)->toBeInstanceOf(User::class);
});

test('deposits store crypto amounts with eight decimal places', function () {
    $deposit = Deposit::factory()->create([
        'gross_amount' => 1.23456789,
        'fee_amount' => 0.12345678,
        'credited_amount' => 1.11111111,
    ]);

    expect($deposit->gross_amount)->toBe('1.23456789')
        ->and($deposit->fee_amount)->toBe('0.12345678')
        ->and($deposit->credited_amount)->toBe('1.11111111');
});

test('deposits are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    $ownerAddress = DepositAddress::factory()->for(
        Customer::create(['user_id' => $owner->id, 'customer_reference' => 'OWNER-CUST'])
    )->create(['network' => 'bitcoin']);

    $otherAddress = DepositAddress::factory()->for(
        Customer::create(['user_id' => $otherOwner->id, 'customer_reference' => 'OTHER-CUST'])
    )->create(['network' => 'bitcoin']);

    Deposit::create([
        'deposit_address_id' => $ownerAddress->id,
        'customer_id' => $ownerAddress->customer_id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'tx_hash' => 'owner-tx',
        'gross_amount' => 1,
        'status' => 'detected',
        'detected_at' => now(),
    ]);
    Deposit::create([
        'deposit_address_id' => $otherAddress->id,
        'customer_id' => $otherAddress->customer_id,
        'user_id' => $otherOwner->id,
        'network' => 'bitcoin',
        'tx_hash' => 'other-tx',
        'gross_amount' => 2,
        'status' => 'detected',
        'detected_at' => now(),
    ]);

    $this->actingAs($owner);

    expect(Deposit::pluck('tx_hash')->toArray())->toBe(['owner-tx']);
});
