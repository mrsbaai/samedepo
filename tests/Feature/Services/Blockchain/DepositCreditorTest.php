<?php

use App\Events\DepositBelowMinimum;
use App\Events\DepositCredited;
use App\Events\DepositForfeited;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\UsdValuation;
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
    expect($deposit->fee_amount)->toBe('0.02000000'); // 2% platform default
    expect($deposit->credited_amount)->toBe('0.98000000');
    expect($deposit->credited_at)->not->toBeNull();

    $balance = Balance::query()->where('user_id', $owner->id)->where('network', 'bitcoin')->first();
    expect($balance)->not->toBeNull();
    expect($balance->amount)->toBe('0.98000000');

    $creditEntry = LedgerEntry::query()->where('deposit_id', $deposit->id)->where('reason', 'deposit_credit')->first();
    $feeEntry = LedgerEntry::query()->where('deposit_id', $deposit->id)->where('reason', 'fee')->first();

    expect($creditEntry)->not->toBeNull();
    expect($creditEntry->amount)->toBe('0.98000000');
    expect($creditEntry->network)->toBe('bitcoin');
    expect($creditEntry->user_id)->toBe($owner->id);

    expect($feeEntry)->not->toBeNull();
    expect($feeEntry->amount)->toBe('-0.02000000');
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
        'confirmation_count' => 3,
    ]);

    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->fee_amount)->toBe('0.10000000');
    expect($deposit->credited_amount)->toBe('1.90000000');
});

test('it marks a confirmed deposit below the platform minimum as below_minimum', function () {
    Event::fake([DepositBelowMinimum::class]);
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
        'confirmation_count' => 20,
    ]);

    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('below_minimum');
    expect($deposit->expires_at)->not->toBeNull();
    expect($deposit->expires_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
    expect($deposit->fee_amount)->toBeNull();
    expect($deposit->credited_amount)->toBeNull();

    expect(Balance::query()->where('user_id', $owner->id)->count())->toBe(0);
    expect(LedgerEntry::query()->where('deposit_id', $deposit->id)->count())->toBe(0);

    Event::assertDispatched(DepositBelowMinimum::class, fn (DepositBelowMinimum $event) => $event->deposit->id === $deposit->id);
});

test('it credits a below_minimum deposit after the platform minimum is lowered and confirmations are met', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_erc20',
        'gross_amount' => '19.70000000',
        'status' => 'below_minimum',
        'confirmation_count' => 12,
    ]);

    PlatformSettings::instance();
    PlatformSettings::networkSetting('usdt_erc20')->update(['min_deposit' => '18.00000000']);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->status)->toBe('credited')
        ->and($deposit->fresh()->credited_amount)->toBe('19.30600000')
        ->and(Balance::query()->where('user_id', $owner->id)->where('network', 'usdt_erc20')->value('amount'))->toBe('19.30600000');
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
        'confirmation_count' => 3,
    ]);

    app(DepositCreditor::class)->credit();
    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited');

    $balance = Balance::query()->where('user_id', $owner->id)->where('network', 'bitcoin')->first();
    expect($balance->amount)->toBe('0.98000000');
    expect(LedgerEntry::query()->where('deposit_id', $deposit->id)->count())->toBe(2);
});

test('it does not credit a pending deposit before required confirmations are reached', function () {
    Event::fake([DepositCredited::class]);
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
        'confirmation_count' => 1,
    ]);

    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('pending');
    expect($deposit->credited_amount)->toBeNull();
    expect(Balance::query()->where('user_id', $owner->id)->count())->toBe(0);
    expect(LedgerEntry::query()->where('deposit_id', $deposit->id)->count())->toBe(0);

    Event::assertNotDispatched(DepositCredited::class);
});

test('it keeps an unconfirmed below-minimum deposit pending', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'status' => 'pending',
        'confirmation_count' => 19,
    ]);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->status)->toBe('pending')
        ->and($deposit->fresh()->fee_amount)->toBeNull()
        ->and($deposit->fresh()->credited_amount)->toBeNull()
        ->and(Balance::query()->where('user_id', $owner->id)->count())->toBe(0)
        ->and(LedgerEntry::query()->where('deposit_id', $deposit->id)->count())->toBe(0);
});

test('it credits a pending deposit once the required confirmations are reached', function () {
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
        'confirmation_count' => 3,
    ]);

    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited');
    expect($deposit->credited_amount)->toBe('0.98000000');
    expect(Balance::query()->where('user_id', $owner->id)->where('network', 'bitcoin')->value('amount'))->toBe('0.98000000');
});

test('it does not credit a below_minimum deposit after the minimum is lowered until confirmations are reached', function () {
    Event::fake([DepositCredited::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_erc20',
        'gross_amount' => '19.70000000',
        'status' => 'below_minimum',
        'confirmation_count' => 11,
    ]);

    PlatformSettings::instance();
    PlatformSettings::networkSetting('usdt_erc20')->update(['min_deposit' => '18.00000000']);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->status)->toBe('below_minimum');
    expect(Balance::query()->where('user_id', $owner->id)->count())->toBe(0);

    $deposit->update(['confirmation_count' => 12]);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->status)->toBe('credited');
    expect($deposit->fresh()->credited_amount)->toBe('19.30600000');
});

test('it stores the usd value at the live rate when crediting', function () {
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
        'confirmation_count' => 3,
    ]);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '60000.000000']);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->usd_value)->toBe('58800.00');
});

test('it rounds the stored usd value instead of truncating it', function () {
    $owner = User::factory()->create(['role' => 'owner', 'deposit_fee_override' => 0]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '2.50000000',
        'status' => 'pending',
        'confirmation_count' => 3,
    ]);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '0.999']);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->usd_value)->toBe('2.50');
});

test('it stores the exact usd value for a stablecoin deposit at rate one', function () {
    $owner = User::factory()->create(['role' => 'owner', 'deposit_fee_override' => 0]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '19.60000000',
        'status' => 'pending',
        'confirmation_count' => 20,
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => '1']);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->usd_value)->toBe('19.60');
});

test('it leaves usd value null when no valuation exists', function () {
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
        'confirmation_count' => 3,
    ]);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->status)->toBe('credited')
        ->and($deposit->fresh()->usd_value)->toBeNull();
});

test('it combines two short payments on one address and credits both with normal fees', function () {
    Event::fake([DepositCredited::class, DepositBelowMinimum::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $first = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'pending',
        'confirmation_count' => 20,
    ]);
    $second = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'status' => 'pending',
        'confirmation_count' => 20,
    ]);

    app(DepositCreditor::class)->credit();

    expect($first->fresh()->status)->toBe('credited')
        ->and($first->fresh()->fee_amount)->toBe('0.12000000')
        ->and($first->fresh()->credited_amount)->toBe('5.88000000')
        ->and($second->fresh()->status)->toBe('credited')
        ->and($second->fresh()->fee_amount)->toBe('0.10000000')
        ->and($second->fresh()->credited_amount)->toBe('4.90000000')
        ->and(Balance::query()->where('user_id', $owner->id)->where('network', 'usdt_trc20')->value('amount'))->toBe('10.78000000');

    Event::assertDispatched(DepositCredited::class, 2);
    Event::assertDispatched(DepositBelowMinimum::class, 1);
    Event::assertDispatched(DepositBelowMinimum::class, fn (DepositBelowMinimum $event) => $event->deposit->id === $first->id);
});

test('it credits a normal payment together with an open short on the same address', function () {
    Event::fake([DepositCredited::class, DepositBelowMinimum::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $short = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'detected_at' => now()->subDay(),
        'expires_at' => now()->addDays(5),
    ]);
    $payment = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '12.00000000',
        'status' => 'pending',
        'confirmation_count' => 20,
    ]);

    app(DepositCreditor::class)->credit();

    expect($short->fresh()->status)->toBe('credited')
        ->and($payment->fresh()->status)->toBe('credited')
        ->and($payment->fresh()->fee_amount)->toBe('0.24000000')
        ->and(Balance::query()->where('user_id', $owner->id)->where('network', 'usdt_trc20')->value('amount'))->toBe('17.64000000');

    Event::assertDispatched(DepositCredited::class, 2);
    Event::assertNotDispatched(DepositBelowMinimum::class);
});

test('later shorts on the same address share the first expiry deadline', function () {
    Event::fake([DepositBelowMinimum::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $first = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'pending',
        'confirmation_count' => 20,
    ]);

    app(DepositCreditor::class)->credit();

    expect($first->fresh()->status)->toBe('below_minimum')
        ->and($first->fresh()->expires_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());

    $second = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '2.00000000',
        'status' => 'pending',
        'confirmation_count' => 20,
    ]);

    app(DepositCreditor::class)->credit();

    expect($second->fresh()->status)->toBe('below_minimum')
        ->and($second->fresh()->expires_at->toDateTimeString())->toBe($first->fresh()->expires_at->toDateTimeString());

    Event::assertDispatched(DepositBelowMinimum::class, 2);
});

test('it never combines shorts on different networks sharing an address', function () {
    Event::fake([DepositBelowMinimum::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $sharedAddress = '0xA0b86a33E6441E6C7D3D4B4e5F6a7B8c9D0e1F2a';
    $usdtAddress = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_erc20',
        'address' => $sharedAddress,
    ]);
    $usdcAddress = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdc_erc20',
        'address' => $sharedAddress,
    ]);
    $usdtShort = Deposit::factory()->create([
        'deposit_address_id' => $usdtAddress->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_erc20',
        'gross_amount' => '6.00000000',
        'status' => 'pending',
        'confirmation_count' => 12,
    ]);
    $usdcShort = Deposit::factory()->create([
        'deposit_address_id' => $usdcAddress->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdc_erc20',
        'gross_amount' => '6.00000000',
        'status' => 'pending',
        'confirmation_count' => 12,
    ]);

    app(DepositCreditor::class)->credit();

    expect($usdtShort->fresh()->status)->toBe('below_minimum')
        ->and($usdcShort->fresh()->status)->toBe('below_minimum')
        ->and(Balance::query()->count())->toBe(0);

    Event::assertDispatched(DepositBelowMinimum::class, 2);
});

test('it credits a payment using its snapshot minimum when the platform minimum was raised', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '12.00000000',
        'minimum_amount' => '10.00000000',
        'status' => 'pending',
        'confirmation_count' => 20,
    ]);

    PlatformSettings::instance();
    PlatformSettings::networkSetting('usdt_trc20')->update(['min_deposit' => '20.00000000']);

    app(DepositCreditor::class)->credit();

    expect($deposit->fresh()->status)->toBe('credited')
        ->and($deposit->fresh()->credited_amount)->toBe('11.76000000');
});

test('it credits an open short when the platform minimum is lowered below it', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $short = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'minimum_amount' => '10.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'expires_at' => now()->addDays(5),
    ]);

    PlatformSettings::instance();
    PlatformSettings::networkSetting('usdt_trc20')->update(['min_deposit' => '5.00000000']);

    app(DepositCreditor::class)->credit();

    expect($short->fresh()->status)->toBe('credited')
        ->and($short->fresh()->credited_amount)->toBe('5.88000000');
});

test('it forfeits shorts whose expiry has passed without touching balances or ledger', function () {
    Event::fake([DepositForfeited::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $short = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'expires_at' => now()->addDays(7),
    ]);

    $this->travel(8)->days();

    app(DepositCreditor::class)->expire();

    expect($short->fresh()->status)->toBe('forfeited')
        ->and($short->fresh()->forfeited_at)->not->toBeNull()
        ->and(Balance::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);

    Event::assertDispatched(DepositForfeited::class, fn (DepositForfeited $event) => $event->deposit->id === $short->id);
});

test('a top-up detected before expiry is credited together with the short in the same run', function () {
    Event::fake([DepositCredited::class, DepositForfeited::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $short = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'detected_at' => now()->subDays(6),
        'expires_at' => now()->addDay(),
    ]);
    $topup = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'status' => 'pending',
        'confirmation_count' => 20,
        'detected_at' => now(),
    ]);

    $this->travel(2)->days(); // short expired yesterday; the top-up arrived before that

    app(DepositCreditor::class)->credit();
    app(DepositCreditor::class)->expire();

    expect($short->fresh()->status)->toBe('credited')
        ->and($topup->fresh()->status)->toBe('credited')
        ->and(Balance::query()->where('user_id', $owner->id)->where('network', 'usdt_trc20')->value('amount'))->toBe('10.78000000');

    Event::assertNotDispatched(DepositForfeited::class);
});

test('an expired short is not forfeited while a top-up detected before the deadline is confirming', function () {
    Event::fake([DepositForfeited::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $short = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'detected_at' => now()->subDays(10),
        'expires_at' => now()->subDay(),
    ]);
    $topup = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'status' => 'pending',
        'confirmation_count' => 10,
        'detected_at' => now()->subDays(2),
    ]);

    app(DepositCreditor::class)->expire();

    expect($short->fresh()->status)->toBe('below_minimum');
    Event::assertNotDispatched(DepositForfeited::class);

    $topup->update(['confirmation_count' => 20]);

    app(DepositCreditor::class)->credit();
    app(DepositCreditor::class)->expire();

    expect($short->fresh()->status)->toBe('credited')
        ->and($topup->fresh()->status)->toBe('credited')
        ->and(Balance::query()->where('user_id', $owner->id)->where('network', 'usdt_trc20')->value('amount'))->toBe('10.78000000');

    Event::assertNotDispatched(DepositForfeited::class);
});

test('a pending top-up detected after the deadline does not protect an expired short', function () {
    Event::fake([DepositForfeited::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $short = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'detected_at' => now()->subDays(10),
        'expires_at' => now()->subDay(),
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'status' => 'pending',
        'confirmation_count' => 10,
        'detected_at' => now(),
    ]);

    app(DepositCreditor::class)->expire();

    expect($short->fresh()->status)->toBe('forfeited')
        ->and($short->fresh()->forfeited_at)->not->toBeNull();

    Event::assertDispatched(DepositForfeited::class, fn (DepositForfeited $event) => $event->deposit->id === $short->id);
});

test('an expired short is not combined with a new payment and starts a fresh window', function () {
    Event::fake([DepositForfeited::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $short = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'detected_at' => now()->subDays(10),
        'expires_at' => now()->subDays(2),
    ]);
    $payment = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '2.00000000',
        'status' => 'pending',
        'confirmation_count' => 20,
        'detected_at' => now(),
    ]);

    app(DepositCreditor::class)->credit();
    app(DepositCreditor::class)->expire();

    expect($payment->fresh()->status)->toBe('below_minimum')
        ->and($payment->fresh()->expires_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString())
        ->and($short->fresh()->status)->toBe('forfeited')
        ->and(Balance::query()->count())->toBe(0);
});

test('it credits a below_minimum deposit manually with the normal fee and audit trail', function () {
    Event::fake([DepositCredited::class]);
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $short = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'expires_at' => now()->addDays(5),
    ]);

    $this->actingAs($admin);
    app(DepositCreditor::class)->creditManually($short, $admin);

    $short->refresh();
    expect($short->status)->toBe('credited')
        ->and($short->fee_amount)->toBe('0.12000000')
        ->and($short->credited_amount)->toBe('5.88000000')
        ->and($short->manually_credited_by)->toBe($admin->id)
        ->and($short->manually_credited_at)->not->toBeNull()
        ->and(Balance::query()->withoutGlobalScope('owner')->where('user_id', $owner->id)->where('network', 'usdt_trc20')->value('amount'))->toBe('5.88000000')
        ->and(LedgerEntry::query()->withoutGlobalScope('owner')->where('deposit_id', $short->id)->count())->toBe(2);

    Event::assertDispatched(DepositCredited::class);
});

test('it credits a forfeited deposit manually and keeps swept_at', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $sweptAt = now()->subDay();
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'forfeited',
        'confirmation_count' => 20,
        'forfeited_at' => now()->subDays(2),
        'swept_at' => $sweptAt,
    ]);

    $this->actingAs($admin);
    app(DepositCreditor::class)->creditManually($deposit, $admin);

    $deposit->refresh();
    expect($deposit->status)->toBe('credited')
        ->and($deposit->credited_amount)->toBe('5.88000000')
        ->and($deposit->manually_credited_by)->toBe($admin->id)
        ->and($deposit->swept_at->toDateTimeString())->toBe($sweptAt->toDateTimeString());
});

test('it refuses to credit a credited deposit manually', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $deposit = Deposit::factory()->create(['status' => 'credited', 'confirmation_count' => 20]);

    $this->actingAs($admin);

    expect(fn () => app(DepositCreditor::class)->creditManually($deposit, $admin))
        ->toThrow(DomainException::class);
});

test('it refuses to credit a deposit covered by an in-flight sweep', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '6.00000000',
        'status' => 'below_minimum',
        'confirmation_count' => 20,
        'expires_at' => now()->addDays(5),
    ]);
    TreasurySweep::create([
        'deposit_address_id' => $address->id,
        'deposit_ids' => [$deposit->id],
        'network' => 'usdt_trc20',
        'amount' => '6.00000000',
        'status' => 'broadcast',
    ]);

    $this->actingAs($admin);

    expect(fn () => app(DepositCreditor::class)->creditManually($deposit, $admin))
        ->toThrow(DomainException::class)
        ->and($deposit->fresh()->status)->toBe('below_minimum');
});
