<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Withdrawal;

test('a withdrawal belongs to an owner', function () {
    $withdrawal = Withdrawal::factory()->create();

    expect($withdrawal->user)->toBeInstanceOf(User::class);
});

test('withdrawals store crypto amounts with eight decimal places', function () {
    $withdrawal = Withdrawal::factory()->create([
        'gross_amount' => 1.23456789,
        'network_fee' => 0.0001,
        'amount_sent' => 1.23446789,
    ]);

    expect($withdrawal->gross_amount)->toBe('1.23456789')
        ->and($withdrawal->network_fee)->toBe('0.00010000')
        ->and($withdrawal->amount_sent)->toBe('1.23446789');
});

test('withdrawals are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    Withdrawal::create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => 1,
        'destination_address' => 'owner-address',
        'mode' => 'instant',
        'status' => 'pending',
    ]);
    Withdrawal::create([
        'user_id' => $otherOwner->id,
        'network' => 'bitcoin',
        'gross_amount' => 2,
        'destination_address' => 'other-address',
        'mode' => 'instant',
        'status' => 'pending',
    ]);

    $this->actingAs($owner);

    expect(Withdrawal::pluck('destination_address')->toArray())->toBe(['owner-address']);
});
