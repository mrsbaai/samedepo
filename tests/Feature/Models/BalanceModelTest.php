<?php

declare(strict_types=1);

use App\Models\Balance;
use App\Models\User;

test('a balance belongs to a website owner', function () {
    $owner = User::factory()->create();
    $balance = Balance::create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'amount' => 1.5,
    ]);

    expect($balance->user->id)->toBe($owner->id);
});

test('balances store crypto amounts with eight decimal places', function () {
    $balance = Balance::factory()->create([
        'network' => 'bitcoin',
        'amount' => 0.12345678,
    ]);

    expect($balance->amount)->toBe('0.12345678');
});

test('balances are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    Balance::create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'amount' => 1,
    ]);
    Balance::create([
        'user_id' => $otherOwner->id,
        'network' => 'bitcoin',
        'amount' => 2,
    ]);

    $this->actingAs($owner);

    expect(Balance::pluck('amount')->toArray())->toBe(['1.00000000']);
});
