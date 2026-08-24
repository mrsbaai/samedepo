<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WithdrawalAddress;

test('a withdrawal address belongs to an owner', function () {
    $owner = User::factory()->create();
    $address = WithdrawalAddress::create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'address' => 'owner-payout-address',
    ]);

    expect($address->user->id)->toBe($owner->id);
});

test('withdrawal addresses are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    WithdrawalAddress::create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'address' => 'owner-address',
    ]);
    WithdrawalAddress::create([
        'user_id' => $otherOwner->id,
        'network' => 'bitcoin',
        'address' => 'other-address',
    ]);

    $this->actingAs($owner);

    expect(WithdrawalAddress::pluck('address')->toArray())->toBe(['owner-address']);
});
