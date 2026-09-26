<?php

use App\Livewire\Admin\Transactions;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use Livewire\Livewire;

function adminLedgerDeposit(User $owner, array $attributes = []): Deposit
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

test('an admin sees deposits and withdrawals from every owner', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $ownerA = User::factory()->create(['role' => 'owner', 'email' => 'owner-a@example.com']);
    $ownerB = User::factory()->create(['role' => 'owner', 'email' => 'owner-b@example.com']);

    adminLedgerDeposit($ownerA, ['tx_hash' => 'a-deposit-hash']);
    adminLedgerDeposit($ownerB, ['tx_hash' => 'b-deposit-hash']);
    Withdrawal::factory()->create([
        'user_id' => $ownerB->id,
        'network' => 'usdt_trc20',
        'status' => 'sent',
        'gross_amount' => '50.00000000',
        'tx_hash' => 'b-withdrawal-hash',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.transactions'))
        ->assertOk()
        ->assertSee('owner-a@example.com')
        ->assertSee('owner-b@example.com')
        ->assertSee('a-deposit-hash', false)
        ->assertSee('b-deposit-hash', false)
        ->assertSee('b-withdrawal-hash', false);
});

test('owners and guests cannot access admin transactions', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->get(route('admin.transactions'))->assertRedirect(route('signin'));

    $this->actingAs($owner)
        ->get(route('admin.transactions'))
        ->assertForbidden();
});

test('search matches owner email, customer reference, and tx hash', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $ownerA = User::factory()->create(['role' => 'owner', 'email' => 'alpha@example.com']);
    $ownerB = User::factory()->create(['role' => 'owner', 'email' => 'beta@example.com']);

    adminLedgerDeposit($ownerA, ['tx_hash' => 'alpha-tx-hash']);
    adminLedgerDeposit($ownerB, ['tx_hash' => 'beta-tx-hash']);

    Livewire::actingAs($admin)
        ->test(Transactions::class)
        ->assertSee('alpha-tx-hash', false)
        ->assertSee('beta-tx-hash', false)
        ->set('search', 'alpha@example.com')
        ->assertSee('alpha-tx-hash', false)
        ->assertDontSee('beta-tx-hash', false)
        ->set('search', 'beta-tx')
        ->assertDontSee('alpha-tx-hash', false)
        ->assertSee('beta-tx-hash', false);
});

test('type, network, and status filters narrow the admin ledger', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);

    adminLedgerDeposit($owner, ['network' => 'bitcoin', 'status' => 'credited', 'tx_hash' => 'dep-tx-hash']);
    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'denied',
        'tx_hash' => 'wd-tx-hash',
    ]);

    Livewire::actingAs($admin)
        ->test(Transactions::class)
        ->assertSee('dep-tx-hash', false)
        ->assertSee('wd-tx-hash', false)
        ->set('typeFilter', 'withdrawal')
        ->assertDontSee('dep-tx-hash', false)
        ->assertSee('wd-tx-hash', false)
        ->set('typeFilter', 'all')
        ->set('networkFilter', 'bitcoin')
        ->assertSee('dep-tx-hash', false)
        ->assertDontSee('wd-tx-hash', false)
        ->set('networkFilter', 'all')
        ->set('statusFilter', 'denied')
        ->assertDontSee('dep-tx-hash', false)
        ->assertSee('wd-tx-hash', false);
});

test('deposit reference links to the admin customer detail', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);

    $deposit = adminLedgerDeposit($owner, ['tx_hash' => 'linked-dep-hash']);
    $reference = $deposit->customer->customer_reference;

    $this->actingAs($admin)
        ->get(route('admin.transactions'))
        ->assertOk()
        ->assertSee(route('admin.owners.customers.show', [$owner->id, $reference]), false)
        ->assertSee(route('admin.owners.show', $owner->id), false);
});

test('an admin can credit a below minimum deposit anyway', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $deposit = adminLedgerDeposit($owner, [
        'network' => 'usdt_trc20',
        'status' => 'below_minimum',
        'gross_amount' => '6.00000000',
        'expires_at' => now()->addDays(5),
        'tx_hash' => 'short-credit-hash',
    ]);

    Livewire::actingAs($admin)
        ->test(Transactions::class)
        ->assertSee('Credit anyway')
        ->call('confirmCreditAnyway', $deposit->id)
        ->assertSet('showCreditModal', true)
        ->call('creditAnyway')
        ->assertSet('showCreditModal', false)
        ->assertSet('creditError', null);

    $deposit->refresh();
    expect($deposit->status)->toBe('credited')
        ->and((int) $deposit->manually_credited_by)->toBe($admin->id)
        ->and($deposit->manually_credited_at)->not->toBeNull()
        ->and($deposit->fee_amount)->toBe('0.12000000')
        ->and($deposit->credited_amount)->toBe('5.88000000');

    expect(LedgerEntry::query()->withoutGlobalScope('owner')->where('deposit_id', $deposit->id)->where('reason', 'deposit_credit')->value('amount'))
        ->toBe('5.88000000');
});

test('credit anyway failure shows the message and changes nothing', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $deposit = adminLedgerDeposit($owner, [
        'status' => 'credited',
        'gross_amount' => '6.00000000',
    ]);

    Livewire::actingAs($admin)
        ->test(Transactions::class)
        ->call('confirmCreditAnyway', $deposit->id)
        ->call('creditAnyway')
        ->assertSet('showCreditModal', false)
        ->assertSet('creditError', 'Only below-minimum or forfeited deposits can be credited manually.');

    expect($deposit->fresh()->manually_credited_by)->toBeNull()
        ->and(LedgerEntry::query()->where('deposit_id', $deposit->id)->count())->toBe(0);
});

test('a non-admin cannot invoke credit anyway', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $deposit = adminLedgerDeposit($owner, [
        'network' => 'usdt_trc20',
        'status' => 'below_minimum',
        'gross_amount' => '6.00000000',
    ]);

    Livewire::actingAs($owner)
        ->test(Transactions::class)
        ->call('confirmCreditAnyway', $deposit->id)
        ->assertForbidden();

    expect($deposit->fresh()->status)->toBe('below_minimum');
});
