<?php

use App\Livewire\Dashboard\Withdraw;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasExpense;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\WithdrawalAddress;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
});

function ownerWithTrc20Balance(string $amount = '100.00000000'): User
{
    $owner = User::factory()->create(['role' => 'owner']);
    WithdrawalAddress::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
    ]);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => $amount]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => '1.00']);
    UsdValuation::factory()->create(['network' => 'native_trx', 'conversion_value' => '0.33']);
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20.00']);

    return $owner;
}

test('withdraw page shows the exact buffered and converted fee breakdown', function () {
    $owner = ownerWithTrc20Balance();
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->once()->with('usdt_trc20', true)->andReturn('5.00000000');
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Estimated network fee')
        ->assertSee('1.98 USDT')
        ->assertSee('$1.98 USD')
        ->assertSee("Estimated amount you'll receive", false)
        ->assertSee('98.02 USDT')
        ->assertSee('Estimates — the final fee is locked when the withdrawal is sent.')
        ->assertSee('How fees are calculated')
        ->assertDontSee('Sweep-gas recovery');
});

test('withdraw page shows sweep gas recovery only when an unrecovered sweep exists', function () {
    $owner = ownerWithTrc20Balance();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $deposit = Deposit::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'deposit_address_id' => $address->id,
        'network' => 'usdt_trc20',
        'status' => 'credited',
    ]);
    $sweep = TreasurySweep::create([
        'deposit_id' => $deposit->id,
        'network' => 'usdt_trc20',
        'amount' => '10.00000000',
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ]);
    GasExpense::create([
        'expensable_type' => TreasurySweep::class,
        'expensable_id' => $sweep->id,
        'network' => 'usdt_trc20',
        'amount' => '1.00000000',
        'tx_hash' => 'sweep-fee-test',
    ]);
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->once()->andReturn('5.00000000');
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Sweep-gas recovery')
        ->assertSee('0.33 USDT')
        ->assertSee('97.69 USDT');
});

test('withdraw fee estimate is cached for five minutes', function () {
    $owner = ownerWithTrc20Balance();
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->once()->andReturn('5.00000000');
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)->test(Withdraw::class, ['network' => 'usdt-trc20']);
    Livewire::actingAs($owner)->test(Withdraw::class, ['network' => 'usdt-trc20']);
});

test('withdraw remains available when the fee estimate fails', function () {
    $owner = ownerWithTrc20Balance();
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->atLeast()->once()->andThrow(new RuntimeException('signer unavailable'));
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Fee estimate unavailable — the exact fee will be deducted at send time.')
        ->assertSee('Withdraw Full Balance');
});
