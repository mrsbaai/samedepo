<?php

use App\Livewire\Dashboard\IncomeCharts;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

function chartDeposit(User $owner, string $network, string $usdValue, ?Carbon $at = null): void
{
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => $network]);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $network,
        'status' => 'credited',
        'credited_at' => $at ?? now(),
        'usd_value' => $usdValue,
    ]);
}

test('income charts render for an owner with credited deposits', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    chartDeposit($owner, 'bitcoin', '60.00');
    chartDeposit($owner, 'usdt_trc20', '40.00');

    Livewire::actingAs($owner)
        ->test(IncomeCharts::class, ['ownerId' => $owner->id])
        ->assertSee('Income')
        ->assertSee('Per network')
        ->assertSee('field="bitcoin"', false)
        ->assertSee('field="usdt_trc20"', false)
        ->assertSee('field="total"', false)
        ->assertSee('$100.00', false)
        ->assertSee('stroke-dasharray', false);
});

test('the dashboard page embeds the income charts section', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    chartDeposit($owner, 'usdt_trc20', '25.00');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Income', false)
        ->assertSeeLivewire(IncomeCharts::class);
});

test('hiding a network removes its series line', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    chartDeposit($owner, 'bitcoin', '60.00');
    chartDeposit($owner, 'usdt_trc20', '40.00');

    Livewire::actingAs($owner)
        ->test(IncomeCharts::class, ['ownerId' => $owner->id])
        ->assertSee('field="usdt_trc20"', false)
        ->set('networks', ['bitcoin'])
        ->assertDontSee('field="usdt_trc20"', false)
        ->assertSee('field="bitcoin"', false);
});

test('chips list only networks with income or a balance and lines skip zero-total networks', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    chartDeposit($owner, 'bitcoin', '60.00');
    chartDeposit($owner, 'usdt_trc20', '40.00', now()->subDays(10));

    Livewire::actingAs($owner)
        ->test(IncomeCharts::class, ['ownerId' => $owner->id])
        ->assertSee('value="bitcoin"', false)
        ->assertSee('value="usdt_trc20"', false)
        ->assertDontSee('value="litecoin"', false)
        ->assertSee('field="bitcoin"', false)
        ->assertDontSee('field="usdt_trc20"', false);
});

test('the empty state replaces the charts when nothing was credited', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(IncomeCharts::class, ['ownerId' => $owner->id])
        ->assertSee('No income in this range')
        ->assertDontSee('field="total"', false);
});

test('the date picker is shown only for the custom range', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    chartDeposit($owner, 'bitcoin', '10.00');

    Livewire::actingAs($owner)
        ->test(IncomeCharts::class, ['ownerId' => $owner->id])
        ->assertDontSee('mode="range"', false)
        ->set('range', 'custom')
        ->assertSee('mode="range"', false);
});

test('platform chips list only networks with platform-wide income or a balance', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    chartDeposit($owner, 'bitcoin', '60.00');
    chartDeposit($owner, 'usdt_trc20', '40.00');

    Livewire::actingAs($admin)
        ->test(IncomeCharts::class, ['ownerId' => null, 'heading' => 'Platform income'])
        ->assertSee('Platform income')
        ->assertSee('value="bitcoin"', false)
        ->assertSee('value="usdt_trc20"', false)
        ->assertDontSee('value="litecoin"', false);
});

test('the donut component renders one arc per segment plus the track and total', function () {
    $html = Blade::render('<x-charts.donut :segments="$segments" :total="$total" />', [
        'segments' => [
            ['label' => 'Bitcoin', 'network' => 'bitcoin', 'value' => 60.0, 'color' => 'amber-500'],
            ['label' => 'USDT (TRC20)', 'network' => 'usdt_trc20', 'value' => 40.0, 'color' => 'emerald-500'],
        ],
        'total' => '$100.00',
    ]);

    expect(substr_count($html, '<circle'))->toBe(3)
        ->and($html)->toContain('$100.00')
        ->and($html)->toContain('60.0%')
        ->and($html)->toContain('40.0%')
        ->and($html)->toContain('text-amber-500')
        ->and($html)->toContain('bg-emerald-500');
});

test('the donut abbreviates a large centre total and keeps the exact value in the title', function () {
    $html = Blade::render('<x-charts.donut :segments="$segments" :total="$total" />', [
        'segments' => [],
        'total' => '$6,280,410.59',
    ]);

    expect($html)->toContain('$6.28M')
        ->and($html)->toContain('title="$6,280,410.59"');
});
