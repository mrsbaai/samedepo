<?php

use App\Livewire\Admin\WebsiteOwnerDetail;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasExpense;
use App\Models\LedgerEntry;
use App\Models\TreasurySweep;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Livewire\Livewire;

function ownerFinanceFixture(): array
{
    Carbon::setTestNow('2026-09-15 12:00:00');

    UsdValuation::updateOrCreate(['network' => 'usdt_trc20'], ['conversion_value' => '1.00']);
    UsdValuation::updateOrCreate(['network' => 'native_trx'], ['conversion_value' => '0.30']);
    UsdValuation::updateOrCreate(['network' => 'bitcoin'], ['conversion_value' => '30000.00']);
    UsdValuation::updateOrCreate(['network' => 'usdt_erc20'], ['conversion_value' => '1.00']);
    UsdValuation::updateOrCreate(['network' => 'native_eth'], ['conversion_value' => '1500.00']);

    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create([
        'role' => 'owner',
        'is_admin' => false,
        'withdrawal_mode' => 'approval',
    ]);
    $otherOwner = User::factory()->create(['role' => 'owner', 'is_admin' => false]);

    $customerRecent = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'recent']);
    $customerOldA = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'old-a']);
    $customerOldB = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'old-b']);
    $customerOldA->forceFill(['created_at' => now()->subDays(60)])->save();
    $customerOldB->forceFill(['created_at' => now()->subDays(60)])->save();

    $otherCustomer = Customer::factory()->create(['user_id' => $otherOwner->id, 'customer_reference' => 'other']);
    $otherAddress = DepositAddress::factory()->create(['customer_id' => $otherCustomer->id, 'network' => 'usdt_trc20']);

    $address = DepositAddress::factory()->create(['customer_id' => $customerRecent->id, 'network' => 'usdt_trc20']);

    Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customerRecent->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-100',
        'gross_amount' => '100.00000000',
        'fee_amount' => '2.00000000',
        'credited_amount' => '98.00000000',
        'confirmation_count' => 6,
        'status' => 'credited',
        'detected_at' => now()->subHour(),
        'credited_at' => now()->subHour(),
    ]);

    Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customerOldA->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-50',
        'gross_amount' => '50.00000000',
        'fee_amount' => '1.00000000',
        'credited_amount' => '49.00000000',
        'confirmation_count' => 6,
        'status' => 'credited',
        'detected_at' => now()->subMonths(3),
        'credited_at' => now()->subMonths(3),
    ]);

    Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customerRecent->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-pending',
        'gross_amount' => '10.00000000',
        'confirmation_count' => 1,
        'status' => 'pending',
        'detected_at' => now()->subHour(),
    ]);

    Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customerRecent->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-ignored',
        'gross_amount' => '0.50000000',
        'confirmation_count' => 0,
        'status' => 'ignored',
        'detected_at' => now()->subHour(),
        'credited_at' => now()->subHour(),
    ]);

    LedgerEntry::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'reason' => 'fee', 'amount' => '-2.00000000']);
    LedgerEntry::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'reason' => 'fee', 'amount' => '-1.00000000']);
    LedgerEntry::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'reason' => 'network_fee', 'amount' => '-0.90000000']);

    Withdrawal::create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'mode' => 'approval',
        'gross_amount' => '60.00000000',
        'network_fee' => '0.90000000',
        'amount_sent' => '59.10000000',
        'status' => 'sent',
        'tx_hash' => 'tx-withdraw-sent',
        'destination_address' => 'dest1',
        'created_at' => now()->subDay(),
        'sent_at' => now()->subDay(),
    ]);

    Withdrawal::create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'mode' => 'approval',
        'gross_amount' => '20.00000000',
        'network_fee' => '0.30000000',
        'amount_sent' => null,
        'status' => 'pending',
        'destination_address' => 'dest2',
        'created_at' => now()->subHour(),
    ]);

    Withdrawal::create([
        'user_id' => $otherOwner->id,
        'network' => 'usdt_trc20',
        'mode' => 'approval',
        'gross_amount' => '999.00000000',
        'network_fee' => '0',
        'amount_sent' => null,
        'status' => 'pending',
        'destination_address' => 'other-dest',
        'created_at' => now()->subHour(),
    ]);

    Balance::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => '67.00000000']);

    $sweepRecovered = TreasurySweep::create([
        'deposit_id' => null,
        'deposit_address_id' => $address->id,
        'deposit_ids' => [],
        'network' => 'usdt_trc20',
        'amount' => '100',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDays(5),
        'fee_recovered_at' => now()->subDays(4),
    ]);
    GasExpense::create(['network' => 'usdt_trc20', 'amount' => '1.50000000', 'expensable_type' => TreasurySweep::class, 'expensable_id' => $sweepRecovered->id]);

    $sweepUnrecovered = TreasurySweep::create([
        'deposit_id' => null,
        'deposit_address_id' => $address->id,
        'deposit_ids' => [],
        'network' => 'usdt_trc20',
        'amount' => '100',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDays(2),
    ]);
    GasExpense::create(['network' => 'usdt_trc20', 'amount' => '2.00000000', 'expensable_type' => TreasurySweep::class, 'expensable_id' => $sweepUnrecovered->id]);

    $decoySweep = TreasurySweep::create([
        'deposit_id' => null,
        'deposit_address_id' => $otherAddress->id,
        'deposit_ids' => [],
        'network' => 'usdt_trc20',
        'amount' => '100',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDays(2),
    ]);
    GasExpense::create(['network' => 'usdt_trc20', 'amount' => '9.00000000', 'expensable_type' => TreasurySweep::class, 'expensable_id' => $decoySweep->id]);

    return ['admin' => $admin, 'owner' => $owner, 'otherOwner' => $otherOwner];
}

test('an admin can view a website owner detail', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create([
        'role' => 'owner',
        'withdrawal_mode' => 'instant',
        'deposit_fee_override' => 0.75,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.owners.show', $owner))
        ->assertOk()
        ->assertSee($owner->email)
        ->assertSee('Instant')
        ->assertSee('0.75%');
});

test('an admin can change an owner withdrawal mode', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'withdrawal_mode' => 'instant']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwnerDetail::class, ['owner' => $owner->id])
        ->set('withdrawalMode', 'approval')
        ->call('confirmSaveMode')
        ->call('saveMode')
        ->assertHasNoErrors()
        ->assertSee('Withdrawal mode updated to Administrator Approval', false);

    expect($owner->fresh()->withdrawal_mode)->toBe('approval');
});

test('an admin can set a deposit fee override', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwnerDetail::class, ['owner' => $owner->id])
        ->set('feeOverride', '2.5')
        ->call('confirmSaveFee')
        ->call('saveFee')
        ->assertHasNoErrors()
        ->assertSee('Deposit fee override set to 2.5%', false);

    expect($owner->fresh()->deposit_fee_override)->toBe('2.50');
});

test('an admin can clear a deposit fee override', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'deposit_fee_override' => 1.5]);

    Livewire::actingAs($admin)
        ->test(WebsiteOwnerDetail::class, ['owner' => $owner->id])
        ->set('feeOverride', '')
        ->call('confirmSaveFee')
        ->call('saveFee')
        ->assertHasNoErrors()
        ->assertSee('Fee override removed', false);

    expect($owner->fresh()->deposit_fee_override)->toBeNull();
});

test('non-existent owner shows not found', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.owners.show', 99999))
        ->assertOk()
        ->assertSee('Owner not found')
        ->assertSee('Back to Owners');
});

test('owners cannot access owner detail', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $other = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.owners.show', $other))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->get(route('admin.owners.show', $owner))->assertRedirect(route('signin'));
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwnerDetail::class, ['owner' => $owner->id])
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load website owner')
        ->call('retry')
        ->assertSet('uiState', 'normal');
});

test('owner detail shows header stats and network breakdown for worked example', function () {
    $f = ownerFinanceFixture();

    $this->actingAs($f['admin'])
        ->get(route('admin.owners.show', $f['owner']))
        ->assertOk()
        ->assertSee($f['owner']->email)
        ->assertSee('Administrator Approval')
        ->assertSee('3', false)
        ->assertSee('+1 in 30 days', false)
        ->assertSee('$150.00', false)
        ->assertSee('$3.90', false)
        ->assertSee('net $2.85', false)
        ->assertSee('$0.60 unrecovered', false)
        ->assertSee('$87.00', false)
        ->assertSee('150.00 USDT', false)
        ->assertSee('59.10', false)
        ->assertSee('3.5 TRX', false)
        ->assertSee('2 TRX', false)
        ->assertSee('87.00 USDT', false)
        ->assertDontSee('View Pending Withdrawals', false)
        ->assertSee('Bitcoin', false)
        ->assertSee('0.00000000 BTC', false);
});

test('growth section contains 12 months with current and three-months-ago values', function () {
    $f = ownerFinanceFixture();

    $this->actingAs($f['admin'])
        ->get(route('admin.owners.show', $f['owner']))
        ->assertOk()
        ->assertSee('Growth', false)
        ->assertSee('Sep 26', false)
        ->assertSee('Jul 26', false);
});

test('customers tab lists only this owners customers with working links', function () {
    $f = ownerFinanceFixture();

    $response = $this->actingAs($f['admin'])
        ->get(route('admin.owners.show', $f['owner']).'?tab=customers')
        ->assertOk();

    $response
        ->assertSee('recent', false)
        ->assertSee('old-a', false)
        ->assertSee('old-b', false)
        ->assertDontSee('other', false)
        ->assertSee(route('admin.owners.customers.show', [$f['owner'], 'recent']), false);
});

test('withdrawals tab shows only this owners withdrawals and filters by status', function () {
    $f = ownerFinanceFixture();

    Livewire::actingAs($f['admin'])
        ->test(WebsiteOwnerDetail::class, ['owner' => $f['owner']->id])
        ->set('tab', 'withdrawals')
        ->assertSee('tx-withdraw-sent', false)
        ->assertSee('60.00 USDT', false)
        ->assertSee('pending', false)
        ->assertSee('20.00 USDT', false)
        ->assertDontSee('999.00 USDT', false)
        ->set('withdrawalStatus', 'sent')
        ->assertSee('tx-withdraw-sent', false)
        ->assertSee('Sent', false)
        ->assertDontSee('20.00 USDT', false);
});

test('missing usd rates show unavailable notice and does not error', function () {
    $f = ownerFinanceFixture();
    UsdValuation::query()->delete();

    $this->actingAs($f['admin'])
        ->get(route('admin.owners.show', $f['owner']))
        ->assertOk()
        ->assertSee('USD rates unavailable', false);
});
