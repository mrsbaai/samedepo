<?php

use App\Events\DepositCredited;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Blockchain\DepositCreditor;
use Illuminate\Support\Facades\Event;

test('it credits a pending deposit and creates ledger entries', function () {
    Event::fake([DepositCredited::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
    ]);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.00000000',
        'status' => 'pending',
        'confirmation_count' => 3,
    ]);

    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited');
    expect($deposit->fee_amount)->toBe('0.01000000'); // 1% platform default
    expect($deposit->credited_amount)->toBe('0.99000000');
    expect($deposit->credited_at)->not->toBeNull();

    $balance = Balance::query()->where('user_id', $owner->id)->where('network', 'bitcoin')->first();
    expect($balance)->not->toBeNull();
    expect($balance->amount)->toBe('0.99000000');

    $creditEntry = LedgerEntry::query()->where('deposit_id', $deposit->id)->where('reason', 'deposit_credit')->first();
    $feeEntry = LedgerEntry::query()->where('deposit_id', $deposit->id)->where('reason', 'fee')->first();

    expect($creditEntry)->not->toBeNull();
    expect($creditEntry->amount)->toBe('0.99000000');
    expect($creditEntry->network)->toBe('bitcoin');
    expect($creditEntry->user_id)->toBe($owner->id);

    expect($feeEntry)->not->toBeNull();
    expect($feeEntry->amount)->toBe('-0.01000000');
    expect($feeEntry->network)->toBe('bitcoin');

    Event::assertDispatched(DepositCredited::class);
});

test('it applies the per-owner deposit fee override', function () {
    $owner = User::factory()->create(['role' => 'owner', 'deposit_fee_override' => 5.00]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '2.00000000',
        'status' => 'pending',
    ]);

    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->fee_amount)->toBe('0.10000000');
    expect($deposit->credited_amount)->toBe('1.90000000');
});

test('it ignores deposits below the platform minimum', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000', // below 10 USDT minimum
        'status' => 'pending',
    ]);

    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('ignored');
    expect($deposit->fee_amount)->toBeNull();
    expect($deposit->credited_amount)->toBeNull();

    expect(Balance::query()->where('user_id', $owner->id)->count())->toBe(0);
    expect(LedgerEntry::query()->where('deposit_id', $deposit->id)->count())->toBe(0);
});

test('it is idempotent and does not credit the same deposit twice', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.00000000',
        'status' => 'pending',
    ]);

    app(DepositCreditor::class)->credit();
    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited');

    $balance = Balance::query()->where('user_id', $owner->id)->where('network', 'bitcoin')->first();
    expect($balance->amount)->toBe('0.99000000');
    expect(LedgerEntry::query()->where('deposit_id', $deposit->id)->count())->toBe(2);
});
