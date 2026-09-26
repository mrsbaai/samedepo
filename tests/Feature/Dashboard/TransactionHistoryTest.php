<?php

use App\Livewire\Dashboard\TransactionHistory;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\LedgerEntry;
use App\Models\UsdValuation;
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
        ->assertSee('Transactions', false)
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

test('the adjustment type filter shows only ledger adjustments', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, ['tx_hash' => 'adj-dep-hash']);
    makeLedgerWithdrawal($owner, ['tx_hash' => 'adj-wd-hash']);
    LedgerEntry::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'amount' => '-0.00100000',
        'reason' => 'consolidation_fee',
    ]);

    $component = Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->set('typeFilter', 'adjustment');

    $entries = collect($component->instance()->paginatedEntries->items());
    expect($entries)->toHaveCount(1)
        ->and($entries->first()['type'])->toBe('adjustment');

    $component->set('typeFilter', 'deposit');

    $entries = collect($component->instance()->paginatedEntries->items());
    expect($entries)->toHaveCount(1)
        ->and($entries->first()['type'])->toBe('deposit');
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

test('pending deposits show confirmation progress in the ledger', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, [
        'network' => 'bitcoin',
        'status' => 'pending',
        'tx_hash' => 'progress-tx-hash',
        'confirmation_count' => 2,
    ]);

    $this->actingAs($owner)
        ->get(route('transactions'))
        ->assertOk()
        ->assertSee('progress-tx-hash', false)
        ->assertSee('Pending · 2/3 confirmations', false);
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

test('search matches customer reference or tx hash across deposits and withdrawals', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-FINDME']);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'status' => 'credited',
        'tx_hash' => 'deposit-plain-hash',
        'detected_at' => now(),
    ]);
    makeLedgerDeposit($owner, ['tx_hash' => 'deposit-other-hash']);
    makeLedgerWithdrawal($owner, ['tx_hash' => 'withdrawal-search-hash']);

    $component = Livewire::actingAs($owner)->test(TransactionHistory::class);

    $component->set('search', 'findme')
        ->assertSee('deposit-plain-hash', false)
        ->assertDontSee('deposit-other-hash', false)
        ->assertDontSee('withdrawal-search-hash', false);

    $component->set('search', 'search-hash')
        ->assertDontSee('deposit-plain-hash', false)
        ->assertSee('withdrawal-search-hash', false);
});

test('date range filter narrows the ledger to the selected days', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, ['tx_hash' => 'old-deposit-hash', 'detected_at' => now()->subDays(10)]);
    makeLedgerWithdrawal($owner, ['tx_hash' => 'new-withdrawal-hash', 'created_at' => now()]);

    Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->assertSee('old-deposit-hash', false)
        ->assertSee('new-withdrawal-hash', false)
        ->set('range', ['start' => now()->subDay()->format('Y-m-d'), 'end' => now()->format('Y-m-d')])
        ->assertDontSee('old-deposit-hash', false)
        ->assertSee('new-withdrawal-hash', false);
});

test('usd column shows the stored value or an approximate current-rate value', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '100000.00']);

    makeLedgerDeposit($owner, [
        'network' => 'bitcoin',
        'status' => 'credited',
        'gross_amount' => '0.50000000',
        'credited_amount' => '0.49000000',
        'usd_value' => '49000.00',
        'tx_hash' => 'stored-usd-hash',
    ]);
    makeLedgerDeposit($owner, [
        'network' => 'bitcoin',
        'status' => 'credited',
        'gross_amount' => '0.10000000',
        'credited_amount' => '0.09800000',
        'usd_value' => null,
        'tx_hash' => 'approx-usd-hash',
    ]);

    Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->assertSee('$49,000.00', false)
        ->assertSee('≈$9,800.00', false);
});

test('the clear button appears only when filters are set and resets them', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    makeLedgerDeposit($owner, ['tx_hash' => 'clearable-tx-hash']);

    Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->assertDontSee('clearFilters', false)
        ->set('statusFilter', 'credited')
        ->assertSee('clearFilters', false)
        ->call('clearFilters')
        ->assertSet('statusFilter', 'all')
        ->assertSet('search', '')
        ->assertSet('range', null)
        ->assertSee('clearable-tx-hash', false);
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

test('a below minimum deposit shows its badge and top-up instructions', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'minimum_amount' => '10.00000000',
        'status' => 'below_minimum',
        'expires_at' => now()->addDays(5),
        'detected_at' => now(),
        'tx_hash' => 'short-tx-hash',
    ]);

    $this->actingAs($owner)
        ->get(route('transactions'))
        ->assertOk()
        ->assertSee('Below minimum')
        ->assertSee('What to tell your customer')
        ->assertSee('6.00 USDT')
        ->assertSee('4.00 USDT')
        ->assertSee(now()->addDays(5)->format('j M Y, H:i'));

    $component = Livewire::actingAs($owner)->test(TransactionHistory::class);
    $short = collect($component->instance()->paginatedEntries->items())->firstWhere('status', 'below_minimum');

    expect($short['usd'])->toBeNull()
        ->and($short['net'])->toBeNull()
        ->and($short['short']['minimum'])->toBe('10.00')
        ->and($short['short']['received_total'])->toBe('6.00')
        ->and($short['short']['amount_needed'])->toBe('4.00');

    $component->set('statusFilter', 'below_minimum')
        ->assertSee('short-tx-hash', false);
});

test('a forfeited deposit shows the expired badge and explanation', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, [
        'network' => 'usdt_trc20',
        'status' => 'forfeited',
        'minimum_amount' => '10.00000000',
        'forfeited_at' => now(),
        'tx_hash' => 'forfeited-tx-hash',
    ]);

    $this->actingAs($owner)
        ->get(route('transactions'))
        ->assertOk()
        ->assertSee('Expired')
        ->assertSee('What happened')
        ->assertSee('Below the 10.00 USDT minimum and not topped up within 7 days');
});

test('another owner\'s short payments are not visible', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $other = User::factory()->create(['role' => 'owner']);

    makeLedgerDeposit($owner, ['tx_hash' => 'my-credited-hash']);
    makeLedgerDeposit($other, ['status' => 'below_minimum', 'tx_hash' => 'their-short-hash']);

    Livewire::actingAs($owner)
        ->test(TransactionHistory::class)
        ->assertSee('my-credited-hash', false)
        ->assertDontSee('their-short-hash', false);
});
