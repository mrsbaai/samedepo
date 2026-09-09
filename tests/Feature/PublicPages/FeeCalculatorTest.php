<?php

use App\Livewire\PublicPages\FeeCalculator;
use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    PlatformSettings::instance()->update([
        'withdrawal_min_usd_usdt_trc20' => '100.00',
        'withdrawal_fee_buffer_percent' => '20.00',
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => '1.00']);
    UsdValuation::factory()->create(['network' => 'native_trx', 'conversion_value' => '0.33']);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->zeroOrMoreTimes()->andReturn('5.00000000');
    app()->instance(BlockchainBroadcaster::class, $broadcaster);
});

test('withdrawal fee calculator is public at its dedicated URL', function () {
    $this->get(route('withdrawal-fees.calculator'))
        ->assertOk()
        ->assertSee('Withdrawal Fee Calculator')
        ->assertDontSee('Deposit');

    $this->get('/fee-calculator')->assertNotFound();
});

test('withdrawal fee calculator shows the estimated fee and received amount', function () {
    Livewire::test(FeeCalculator::class)
        ->set('network', 'usdt_trc20')
        ->set('amount', '200')
        ->assertSee('1.98 USDT')
        ->assertSee('198.02 USDT')
        ->assertDontSee('Platform fee')
        ->assertDontSee('Credited amount')
        ->assertDontSee('network costs from consolidating deposits');
});

test('USDT minimum checks do not depend on a valuation row', function () {
    UsdValuation::query()->where('network', 'usdt_trc20')->delete();

    Livewire::test(FeeCalculator::class)
        ->set('network', 'usdt_trc20')
        ->set('amount', '200')
        ->assertDontSee('The minimum withdrawal is');
});

test('bitcoin minimum check is skipped when no price is known', function () {
    Livewire::test(FeeCalculator::class)
        ->set('network', 'bitcoin')
        ->set('amount', '0.001')
        ->assertDontSee('The minimum withdrawal is');
});

test('bitcoin minimum shows the BTC equivalent when a price is known', function () {
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '100000.00']);

    Livewire::test(FeeCalculator::class)
        ->set('network', 'bitcoin')
        ->set('amount', '0.0005')
        ->assertSee('The minimum withdrawal is $100.00 USD (0.00100000 BTC) for Bitcoin.');
});

test('an unavailable fee estimate is cached instead of retried on every render', function () {
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->once()->andThrow(new RuntimeException('down'));
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::test(FeeCalculator::class)
        ->set('amount', '200')
        ->assertSee('Fee estimate unavailable')
        ->set('amount', '300')
        ->assertSee('Fee estimate unavailable');
});

test('an unknown network falls back to the default', function () {
    Livewire::test(FeeCalculator::class)
        ->set('network', 'dogecoin')
        ->assertSet('network', 'usdt_trc20');
});

test('below minimum amounts show the withdrawal minimum and no negative result', function () {
    Livewire::test(FeeCalculator::class)
        ->set('network', 'usdt_trc20')
        ->set('amount', '5')
        ->assertSee('The minimum withdrawal is $100.00 USD (100.00 USDT) for USDT (TRC20).')
        ->assertDontSee('Estimated amount received');
});
