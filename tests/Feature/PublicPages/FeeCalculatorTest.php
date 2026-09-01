<?php

use App\Livewire\PublicPages\FeeCalculator;
use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Models\User;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    PlatformSettings::instance()->update([
        'global_deposit_fee_percent' => '2.00',
        'min_deposit_usdt_trc20' => '10.00000000',
        'withdrawal_min_usd_usdt_trc20' => '100.00',
        'withdrawal_fee_buffer_percent' => '20.00',
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => '1.00']);
    UsdValuation::factory()->create(['network' => 'native_trx', 'conversion_value' => '0.33']);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->zeroOrMoreTimes()->andReturn('5.00000000');
    app()->instance(BlockchainBroadcaster::class, $broadcaster);
});

test('fee calculator is public and shows the global deposit fee', function () {
    $this->get(route('fees.calculator'))
        ->assertOk()
        ->assertSee('Fee Calculator')
        ->assertSee('2.00%');

    Livewire::test(FeeCalculator::class)
        ->set('network', 'usdt_trc20')
        ->set('amount', '200')
        ->assertSee('4.00 USDT')
        ->assertSee('196.00 USDT')
        ->assertSee('1.98 USDT')
        ->assertSee('198.02 USDT');
});

test('authenticated owner sees their deposit fee override', function () {
    $owner = User::factory()->create(['role' => 'owner', 'deposit_fee_override' => '1.50']);

    Livewire::actingAs($owner)
        ->test(FeeCalculator::class)
        ->set('amount', '200')
        ->assertSee('1.50%')
        ->assertSee('3.00 USDT')
        ->assertSee('197.00 USDT');
});

test('below minimum amounts show minimum messages and no negative results', function () {
    Livewire::test(FeeCalculator::class)
        ->set('network', 'usdt_trc20')
        ->set('amount', '5')
        ->assertSee('Below the 10.00 USDT minimum — deposits under this amount are not credited.')
        ->assertSee('The minimum withdrawal is $100.00 USD for USDT (TRC20).')
        ->assertDontSee('Estimated amount received');
});
