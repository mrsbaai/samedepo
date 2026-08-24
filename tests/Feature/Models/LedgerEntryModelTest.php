<?php

declare(strict_types=1);

use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;

test('a ledger entry belongs to an owner and optional deposit or withdrawal', function () {
    $entry = LedgerEntry::factory()->create();

    expect($entry->user)->toBeInstanceOf(User::class)
        ->and($entry->deposit)->toBeNull()
        ->and($entry->withdrawal)->toBeNull();
});

test('a ledger entry can be linked to a deposit', function () {
    $deposit = Deposit::factory()->create();
    $entry = LedgerEntry::create([
        'user_id' => $deposit->user_id,
        'network' => $deposit->network,
        'amount' => 1,
        'reason' => 'deposit_credit',
        'deposit_id' => $deposit->id,
    ]);

    expect($entry->deposit->id)->toBe($deposit->id);
});

test('a ledger entry can be linked to a withdrawal', function () {
    $withdrawal = Withdrawal::factory()->create();
    $entry = LedgerEntry::create([
        'user_id' => $withdrawal->user_id,
        'network' => $withdrawal->network,
        'amount' => -1,
        'reason' => 'withdrawal_reserve',
        'withdrawal_id' => $withdrawal->id,
    ]);

    expect($entry->withdrawal->id)->toBe($withdrawal->id);
});

test('ledger entries are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    LedgerEntry::create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'amount' => 1,
        'reason' => 'deposit_credit',
    ]);
    LedgerEntry::create([
        'user_id' => $otherOwner->id,
        'network' => 'bitcoin',
        'amount' => 2,
        'reason' => 'deposit_credit',
    ]);

    $this->actingAs($owner);

    expect(LedgerEntry::pluck('amount')->toArray())->toBe(['1.00000000']);
});
