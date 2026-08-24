<?php

use App\Livewire\Dashboard\TransactionHistory;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use App\Models\Withdrawal;
use Livewire\Livewire;

function makeLedgerDeposit(User $owner, array $attributes = []): Deposit
{
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => $attributes['network'] ?? 'bitcoin',
    ]);

    return Deposit::factory()->create(array_merge([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $address->network,
        'status' => 'credited',
        'detected_at' => now(),
    ], $attributes));
}

function makeLedgerWithdrawal(User $owner, array $attributes = []): Withdrawal
{
    return Withdrawal::factory()->create(array_merge([
        'user_id' => $owner->id,
        'status' => 'pending',
    ], $attributes));
}

test('an owner can view both deposits and withdrawals on the transactions page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, [
        'network' => 'bitcoin',
        'status' => 'credited',
        'gross_amount' => '0.12345678',
    ]);

    makeLedgerWithdrawal($owner, [
        'network' => 'usdt_trc20',
        'status' => 'sent',
        'gross_amount' => '100.00',
    ]);

    $this->actingAs($owner)
        ->get(route('transactions'))
        ->assertOk()
        ->assertSee('Transaction History', false)
        ->assertSee('Deposit', false)
        ->assertSee('Withdrawal', false)
        ->assertSee('0.12345678 BTC', false)
        ->assertSee('100.00 USDT', false);
});

test('an owner only sees their own transactions', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $otherOwner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, ['tx_hash' => 'mine-tx-hash']);
    makeLedgerDeposit($otherOwner, ['tx_hash' => 'theirs-tx-hash']);

    $this->actingAs($owner)
        ->get(route('transactions'))
        ->assertOk()
        ->assertSee('mine-tx-hash', false)
        ->assertDontSee('theirs-tx-hash', false);
});

test('type filter shows only deposits or only withdrawals', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, ['tx_hash' => 'dep-tx-hash']);
    makeLedgerWithdrawal($owner, ['tx_hash' => 'wd-tx-hash']);

    Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->assertSee('dep-tx-hash', false)
        ->assertSee('wd-tx-hash', false)
        ->set('typeFilter', 'deposit')
        ->assertSee('dep-tx-hash', false)
        ->assertDontSee('wd-tx-hash', false)
        ->set('typeFilter', 'withdrawal')
        ->assertDontSee('dep-tx-hash', false)
        ->assertSee('wd-tx-hash', false);
});

test('network filter narrows the ledger to a single network', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, ['network' => 'bitcoin', 'tx_hash' => 'btc-tx-hash']);
    makeLedgerWithdrawal($owner, ['network' => 'usdt_erc20', 'tx_hash' => 'erc-tx-hash']);

    Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->assertSee('btc-tx-hash', false)
        ->assertSee('erc-tx-hash', false)
        ->set('networkFilter', 'bitcoin')
        ->assertSee('btc-tx-hash', false)
        ->assertDontSee('erc-tx-hash', false)
        ->set('networkFilter', 'usdt-erc20')
        ->assertDontSee('btc-tx-hash', false)
        ->assertSee('erc-tx-hash', false);
});

test('status filter narrows the ledger to a single status', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, ['status' => 'pending', 'tx_hash' => 'pending-tx-hash']);
    makeLedgerWithdrawal($owner, ['status' => 'denied', 'tx_hash' => 'denied-tx-hash']);

    Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->assertSee('pending-tx-hash', false)
        ->assertSee('denied-tx-hash', false)
        ->set('statusFilter', 'pending')
        ->assertSee('pending-tx-hash', false)
        ->assertDontSee('denied-tx-hash', false)
        ->set('statusFilter', 'denied')
        ->assertDontSee('pending-tx-hash', false)
        ->assertSee('denied-tx-hash', false);
});

test('pagination works across combined pages of transactions', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    foreach (range(1, 7) as $i) {
        makeLedgerDeposit($owner, ['detected_at' => now()->subMinutes($i)]);
    }

    foreach (range(1, 6) as $i) {
        makeLedgerWithdrawal($owner, ['created_at' => now()->subMinutes($i + 10)]);
    }

    $component = Livewire::actingAs($owner)->test(TransactionHistory::class);

    expect($component->instance()->paginatedEntries->total())->toBe(13);
    expect($component->instance()->paginatedEntries->count())->toBe(10);

    $component->call('nextPage');

    expect($component->instance()->paginatedEntries->count())->toBe(3);
});

test('empty state is shown when there are no transactions', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('transactions'))
        ->assertOk()
        ->assertSee('No transactions yet. Deposits and withdrawals will show up here once they happen.', false);
});

test('error state renders a callout and retry resets to normal', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('transactions', ['state' => 'error']))
        ->assertOk()
        ->assertSeeText("Couldn't load transactions");

    Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->set('uiState', 'error')
        ->assertSeeText("Couldn't load transactions")
        ->call('retry')
        ->assertSet('uiState', 'normal');
});

test('admins cannot access the transaction history page', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('transactions'))
        ->assertForbidden();
});
