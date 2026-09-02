<?php

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
use App\Services\Blockchain\OwnerFinanceCalculator;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    UsdValuation::query()->delete();
    UsdValuation::create(['network' => 'bitcoin', 'conversion_value' => '65000.000000']);
    UsdValuation::create(['network' => 'usdt_trc20', 'conversion_value' => '1.000000']);
    UsdValuation::create(['network' => 'usdt_erc20', 'conversion_value' => '1.000000']);
    UsdValuation::create(['network' => 'native_trx', 'conversion_value' => '0.300000']);
    UsdValuation::create(['network' => 'native_eth', 'conversion_value' => '3000.000000']);
});

function seedOwnerO(): User
{
    $owner = User::factory()->create([
        'role' => 'owner',
        'is_admin' => false,
        'email' => 'owner@example.test',
    ]);

    $c1 = Customer::create(['user_id' => $owner->id, 'customer_reference' => 'customer-1']);
    $c1->forceFill(['created_at' => now()->subDays(10)])->save();
    $c2 = Customer::create(['user_id' => $owner->id, 'customer_reference' => 'customer-2']);
    $c2->forceFill(['created_at' => now()->subDays(60)])->save();
    $c3 = Customer::create(['user_id' => $owner->id, 'customer_reference' => 'customer-3']);
    $c3->forceFill(['created_at' => now()->subDays(60)])->save();

    $addr1 = DepositAddress::create(['customer_id' => $c1->id, 'network' => 'usdt_trc20', 'address' => 'addr-1']);
    $addr2 = DepositAddress::create(['customer_id' => $c2->id, 'network' => 'usdt_trc20', 'address' => 'addr-2']);

    $d1 = Deposit::create([
        'deposit_address_id' => $addr1->id,
        'customer_id' => $c1->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-d1',
        'gross_amount' => '100.00000000',
        'fee_amount' => '2.00000000',
        'credited_amount' => '98.00000000',
        'confirmation_count' => 6,
        'status' => 'credited',
        'detected_at' => now()->subDays(12),
        'credited_at' => now()->subDays(11),
    ]);

    $d2 = Deposit::create([
        'deposit_address_id' => $addr2->id,
        'customer_id' => $c2->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-d2',
        'gross_amount' => '50.00000000',
        'fee_amount' => '1.00000000',
        'credited_amount' => '49.00000000',
        'confirmation_count' => 6,
        'status' => 'credited',
        'detected_at' => now()->subMonths(3)->subDay(),
        'credited_at' => now()->subMonths(3),
    ]);

    Deposit::create([
        'deposit_address_id' => $addr1->id,
        'customer_id' => $c1->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-pending',
        'gross_amount' => '10.00000000',
        'fee_amount' => null,
        'credited_amount' => null,
        'confirmation_count' => 1,
        'status' => 'pending',
        'detected_at' => now()->subDay(),
        'credited_at' => null,
    ]);

    Deposit::create([
        'deposit_address_id' => $addr1->id,
        'customer_id' => $c1->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-ignored',
        'gross_amount' => '0.50000000',
        'fee_amount' => null,
        'credited_amount' => null,
        'confirmation_count' => 0,
        'status' => 'ignored',
        'detected_at' => now()->subDay(),
        'credited_at' => null,
    ]);

    LedgerEntry::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'reason' => 'fee', 'amount' => '-2.00000000', 'deposit_id' => $d1->id]);
    LedgerEntry::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'reason' => 'fee', 'amount' => '-1.00000000', 'deposit_id' => $d2->id]);
    LedgerEntry::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'reason' => 'network_fee', 'amount' => '-0.90000000']);

    Withdrawal::create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '60.00000000',
        'network_fee' => '0.90000000',
        'network_fee_native' => '3.00000000',
        'amount_sent' => '59.10000000',
        'destination_address' => 'wd-addr-1',
        'mode' => 'approval',
        'status' => 'sent',
        'tx_hash' => 'tx-wd1',
        'sent_at' => now()->subDays(5),
    ]);

    Withdrawal::create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '20.00000000',
        'network_fee' => null,
        'network_fee_native' => null,
        'amount_sent' => null,
        'destination_address' => 'wd-addr-2',
        'mode' => 'approval',
        'status' => 'pending',
    ]);

    Balance::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => '67.00000000']);

    $sweep1 = TreasurySweep::create([
        'deposit_id' => $d1->id,
        'network' => 'usdt_trc20',
        'amount' => '0.00000000',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDays(10),
        'fee_recovered_at' => now()->subDays(9),
    ]);

    GasExpense::create([
        'expensable_type' => TreasurySweep::class,
        'expensable_id' => $sweep1->id,
        'network' => 'native_trx',
        'tx_hash' => 'tx-gas-1',
        'amount' => '1.50000000',
    ]);

    $sweep2 = TreasurySweep::create([
        'deposit_id' => $d2->id,
        'network' => 'usdt_trc20',
        'amount' => '0.00000000',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDays(10),
        'fee_recovered_at' => null,
    ]);

    GasExpense::create([
        'expensable_type' => TreasurySweep::class,
        'expensable_id' => $sweep2->id,
        'network' => 'native_trx',
        'tx_hash' => 'tx-gas-2',
        'amount' => '2.00000000',
    ]);

    return $owner;
}

function seedOtherOwner(): User
{
    $owner = User::factory()->create([
        'role' => 'owner',
        'is_admin' => false,
        'email' => 'other@example.test',
    ]);

    $customer = Customer::create(['user_id' => $owner->id, 'customer_reference' => 'other-1', 'created_at' => now()->subDays(5)]);
    $address = DepositAddress::create(['customer_id' => $customer->id, 'network' => 'usdt_trc20', 'address' => 'addr-other']);

    $deposit = Deposit::create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-other',
        'gross_amount' => '999.00000000',
        'fee_amount' => null,
        'credited_amount' => null,
        'confirmation_count' => 6,
        'status' => 'credited',
        'detected_at' => now()->subDay(),
        'credited_at' => now()->subDay(),
    ]);

    $sweep = TreasurySweep::create([
        'deposit_id' => $deposit->id,
        'network' => 'usdt_trc20',
        'amount' => '0.00000000',
        'status' => 'confirmed',
        'confirmed_at' => now()->subDay(),
        'fee_recovered_at' => null,
    ]);

    GasExpense::create([
        'expensable_type' => TreasurySweep::class,
        'expensable_id' => $sweep->id,
        'network' => 'native_trx',
        'tx_hash' => 'tx-gas-other',
        'amount' => '9.00000000',
    ]);

    return $owner;
}

test('summary returns the worked example values', function () {
    $owner = seedOwnerO();
    seedOtherOwner();

    $summary = app(OwnerFinanceCalculator::class)->summary($owner);

    expect($summary['customers_total'])->toBe(3)
        ->and($summary['customers_new_30d'])->toBe(1)
        ->and($summary['deposits_count'])->toBe(2)
        ->and($summary['rates_available'])->toBeTrue();

    $trc = $summary['networks']['usdt_trc20'];

    expect($trc['deposit_volume'])->toBe('150.00000000')
        ->and($trc['deposit_volume_usd'])->toBe('150.00000000')
        ->and($trc['withdrawn'])->toBe('59.10000000')
        ->and($trc['withdrawn_usd'])->toBe('59.10000000')
        ->and($trc['fee_revenue'])->toBe('3.00000000')
        ->and($trc['withdrawal_fee_revenue'])->toBe('0.90000000')
        ->and($trc['revenue_usd'])->toBe('3.90000000')
        ->and($trc['sweep_gas_native'])->toBe('3.50000000')
        ->and($trc['sweep_gas_usd'])->toBe('1.05000000')
        ->and($trc['unrecovered_gas_native'])->toBe('2.00000000')
        ->and($trc['unrecovered_gas_usd'])->toBe('0.60000000')
        ->and($trc['balance'])->toBe('67.00000000')
        ->and($trc['reserved'])->toBe('20.00000000')
        ->and($trc['owed'])->toBe('87.00000000')
        ->and($trc['owed_usd'])->toBe('87.00000000');

    expect($summary['totals']['deposit_volume_usd'])->toBe('150.00000000')
        ->and($summary['totals']['withdrawn_usd'])->toBe('59.10000000')
        ->and($summary['totals']['revenue_usd'])->toBe('3.90000000')
        ->and($summary['totals']['sweep_gas_usd'])->toBe('1.05000000')
        ->and($summary['totals']['unrecovered_gas_usd'])->toBe('0.60000000')
        ->and($summary['totals']['net_usd'])->toBe('2.85000000')
        ->and($summary['totals']['owed_usd'])->toBe('87.00000000');
});

test('summary returns zeros for networks with no activity', function () {
    $owner = seedOwnerO();

    $summary = app(OwnerFinanceCalculator::class)->summary($owner);
    $btc = $summary['networks']['bitcoin'];

    expect($btc['deposit_volume'])->toBe('0.00000000')
        ->and($btc['deposit_volume_usd'])->toBe('0.00000000')
        ->and($btc['withdrawn'])->toBe('0.00000000')
        ->and($btc['fee_revenue'])->toBe('0.00000000')
        ->and($btc['withdrawal_fee_revenue'])->toBe('0.00000000')
        ->and($btc['revenue_usd'])->toBe('0.00000000')
        ->and($btc['sweep_gas_native'])->toBe('0.00000000')
        ->and($btc['sweep_gas_usd'])->toBe('0.00000000')
        ->and($btc['unrecovered_gas_native'])->toBe('0.00000000')
        ->and($btc['unrecovered_gas_usd'])->toBe('0.00000000')
        ->and($btc['balance'])->toBe('0.00000000')
        ->and($btc['reserved'])->toBe('0.00000000')
        ->and($btc['owed'])->toBe('0.00000000')
        ->and($btc['owed_usd'])->toBe('0.00000000');
});

test('summary never includes another owner data', function () {
    $owner = seedOwnerO();
    $other = seedOtherOwner();

    $summary = app(OwnerFinanceCalculator::class)->summary($owner);

    expect($summary['customers_total'])->toBe(3)
        ->and($summary['networks']['usdt_trc20']['sweep_gas_native'])->toBe('3.50000000');

    $otherSummary = app(OwnerFinanceCalculator::class)->summary($other);

    expect($otherSummary['customers_total'])->toBe(1)
        ->and($otherSummary['networks']['usdt_trc20']['deposit_volume'])->toBe('999.00000000')
        ->and($otherSummary['networks']['usdt_trc20']['sweep_gas_native'])->toBe('9.00000000');
});

test('summary marks rates unavailable when a required rate is missing', function () {
    $owner = seedOwnerO();
    UsdValuation::query()->where('network', 'native_trx')->delete();

    $summary = app(OwnerFinanceCalculator::class)->summary($owner);

    expect($summary['rates_available'])->toBeFalse()
        ->and($summary['networks']['usdt_trc20']['sweep_gas_usd'])->toBe('0.00000000')
        ->and($summary['networks']['usdt_trc20']['deposit_volume'])->toBe('150.00000000')
        ->and($summary['networks']['usdt_trc20']['revenue_usd'])->toBe('3.90000000');
});

test('growth returns twelve monthly buckets with correct values', function () {
    $owner = seedOwnerO();

    $growth = app(OwnerFinanceCalculator::class)->growth($owner);

    expect($growth)->toHaveCount(12);
    expect($growth[0]['month'])->toBe('2025-10-01')
        ->and($growth[11]['month'])->toBe('2026-09-01')
        ->and($growth[11]['deposits_usd'])->toBe(100.0)
        ->and($growth[11]['new_customers'])->toBe(1)
        ->and($growth[8]['deposits_usd'])->toBe(50.0)
        ->and($growth[8]['new_customers'])->toBe(0)
        ->and($growth[9]['deposits_usd'])->toBe(0.0)
        ->and($growth[9]['new_customers'])->toBe(2);

    $zeroMonths = collect($growth)->filter(fn ($row) => $row['deposits_usd'] === 0.0 && $row['new_customers'] === 0);
    expect($zeroMonths)->toHaveCount(9);
});

test('growth excludes deposits and customers older than the window', function () {
    $owner = seedOwnerO();

    Deposit::create([
        'deposit_address_id' => DepositAddress::where('customer_id', $owner->customers()->first()->id)->first()->id,
        'customer_id' => $owner->customers()->first()->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-old',
        'gross_amount' => '999.00000000',
        'fee_amount' => null,
        'credited_amount' => null,
        'confirmation_count' => 6,
        'status' => 'credited',
        'detected_at' => now()->subMonths(13),
        'credited_at' => now()->subMonths(13),
    ]);

    $growth = app(OwnerFinanceCalculator::class)->growth($owner);

    expect(collect($growth)->sum('deposits_usd'))->toBe(150.0);
});

test('withdrawals builder returns only the owners withdrawals newest first', function () {
    $owner = seedOwnerO();
    $other = seedOtherOwner();

    Withdrawal::create([
        'user_id' => $other->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'network_fee' => null,
        'network_fee_native' => null,
        'amount_sent' => null,
        'destination_address' => 'other-wd',
        'mode' => 'approval',
        'status' => 'pending',
    ]);

    $rows = app(OwnerFinanceCalculator::class)->withdrawals($owner)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->first()->gross_amount)->toBe('60.00000000')
        ->and($rows->pluck('user_id')->unique()->first())->toBe($owner->id);
});
