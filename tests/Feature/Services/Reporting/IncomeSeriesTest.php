<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use App\Services\Reporting\IncomeSeries;
use Illuminate\Support\Carbon;

function incomeDeposit(User $owner, string $network, string $usdValue, Carbon $creditedAt): Deposit
{
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => $network]);

    return Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $network,
        'status' => 'credited',
        'credited_at' => $creditedAt,
        'usd_value' => $usdValue,
    ]);
}

test('it buckets by hour when the range is a day or less', function () {
    Carbon::setTestNow('2026-09-20 15:30:00');
    $owner = User::factory()->create(['role' => 'owner']);
    incomeDeposit($owner, 'bitcoin', '10.00', now()->subHours(2));
    incomeDeposit($owner, 'usdt_trc20', '5.50', now()->subHours(2));

    $series = IncomeSeries::build($owner->id, today(), now(), ['bitcoin', 'usdt_trc20']);

    expect($series['bucket'])->toBe('hour');
    $point = collect($series['points'])->firstWhere('t', now()->subHours(2)->format('Y-m-d\TH:00:00'));
    expect($point['total'])->toBe(15.5)
        ->and($point['bitcoin'])->toBe(10.0)
        ->and($point['usdt_trc20'])->toBe(5.5)
        ->and($series['totals'])->toBe(['bitcoin' => 10.0, 'usdt_trc20' => 5.5]);

    Carbon::setTestNow();
});

test('it buckets by day for ranges up to sixty days', function () {
    Carbon::setTestNow('2026-09-20 15:30:00');
    $owner = User::factory()->create(['role' => 'owner']);
    incomeDeposit($owner, 'bitcoin', '20.00', now()->subDays(3)->setHour(10));

    $series = IncomeSeries::build($owner->id, today()->subDays(6), now(), ['bitcoin']);

    expect($series['bucket'])->toBe('day')
        ->and($series['points'])->toHaveCount(7)
        ->and(collect($series['points'])->firstWhere('t', now()->subDays(3)->format('Y-m-d'))['total'])->toBe(20.0)
        ->and(collect($series['points'])->firstWhere('t', today()->format('Y-m-d'))['total'])->toBe(0.0);

    Carbon::setTestNow();
});

test('it buckets by month beyond sixty days', function () {
    Carbon::setTestNow('2026-09-20 15:30:00');
    $owner = User::factory()->create(['role' => 'owner']);
    incomeDeposit($owner, 'usdt_trc20', '40.00', Carbon::parse('2026-06-10'));

    $series = IncomeSeries::build($owner->id, Carbon::parse('2026-04-01'), now(), ['usdt_trc20']);

    expect($series['bucket'])->toBe('month')
        ->and(collect($series['points'])->firstWhere('t', '2026-06')['usdt_trc20'])->toBe(40.0)
        ->and(collect($series['points'])->firstWhere('t', '2026-05')['total'])->toBe(0.0);

    Carbon::setTestNow();
});

test('it filters by owner and network', function () {
    Carbon::setTestNow('2026-09-20 15:30:00');
    $owner = User::factory()->create(['role' => 'owner']);
    $other = User::factory()->create(['role' => 'owner']);
    incomeDeposit($owner, 'bitcoin', '10.00', now());
    incomeDeposit($owner, 'usdt_trc20', '30.00', now());
    incomeDeposit($other, 'bitcoin', '99.00', now());

    $series = IncomeSeries::build($owner->id, today()->subDays(6), now(), ['bitcoin']);

    expect($series['totals'])->toBe(['bitcoin' => 10.0]);

    $platform = IncomeSeries::build(null, today()->subDays(6), now(), ['bitcoin', 'usdt_trc20']);

    expect($platform['totals'])->toBe(['bitcoin' => 109.0, 'usdt_trc20' => 30.0]);

    Carbon::setTestNow();
});

test('it reports each network share of the total', function () {
    Carbon::setTestNow('2026-09-20 15:30:00');
    $owner = User::factory()->create(['role' => 'owner']);
    incomeDeposit($owner, 'bitcoin', '75.00', now());
    incomeDeposit($owner, 'usdt_trc20', '25.00', now());

    $series = IncomeSeries::build($owner->id, today()->subDays(6), now(), ['bitcoin', 'usdt_trc20']);

    expect($series['share'])->toBe([
        ['label' => 'Bitcoin', 'network' => 'bitcoin', 'value' => 75.0],
        ['label' => 'USDT (TRC20)', 'network' => 'usdt_trc20', 'value' => 25.0],
    ]);

    Carbon::setTestNow();
});

test('it returns an empty share and zeroed points when nothing was credited', function () {
    $series = IncomeSeries::build(null, today()->subDays(6), now(), ['bitcoin']);

    expect($series['share'])->toBe([])
        ->and($series['totals'])->toBe(['bitcoin' => 0.0])
        ->and(array_sum(array_column($series['points'], 'total')))->toBe(0.0);
});
