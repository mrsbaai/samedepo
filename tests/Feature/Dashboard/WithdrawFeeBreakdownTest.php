<?php

use App\Livewire\Dashboard\Withdraw;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalAddress;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
    $broadcaster->shouldReceive('estimateTransferResources')->once()
        ->with('usdt_trc20', true, 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA')
        ->andReturn(['fee' => '5.00000000', 'energy' => null]);
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Network fee (up to)')
        ->assertSee('5.00000000 TRX + 20% buffer')
        ->assertSee('1.98 USDT')
        ->assertSee('$1.98 USD')
        ->assertSee('Total fees')
        ->assertSee("Estimated amount you'll receive", false)
        ->assertSee('98.02 USDT')
        ->assertSee('Estimates — the final fee is locked when the withdrawal is sent.')
        ->assertSee("SameDepo's 2% fee was taken when each deposit was credited", false)
        ->assertSee('How this is calculated')
        ->assertSee('How fees are calculated')
        ->assertDontSee('Consolidation already incurred')
        ->assertDontSee('Consolidation to fund this withdrawal');
});

test('withdraw page shows rental method and pending consolidation estimate', function () {
    $owner = ownerWithTrc20Balance();
    TreasuryWallet::factory()->create([
        'network' => 'usdt_trc20',
        'address' => 'TTreasury',
        'available_funds' => '1.00000000',
    ]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'energy_mode' => 'rent',
        'rent_max_price_sun' => 90,
        'rent_duration_sec' => 3600,
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
        'derivation_index' => 3,
        'address' => 'TDeposit3',
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'credited',
        'swept_at' => null,
    ]);

    Http::fake([
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 4160000, 'availableResource' => 100000]]),
    ]);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateTransferResources')->andReturn(['fee' => '5.00000000', 'energy' => 64285]);
    $broadcaster->shouldReceive('getTronResource')->andReturn(['activated' => true, 'energy_limit' => 0]);
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('rented TRON energy')
        ->assertSee('Consolidation to fund this withdrawal (estimate)')
        ->assertSee('1 deposit address still to sweep')
        ->assertSee('Total fees');
});

test('withdraw page shows miner fee for a native coin with no consolidation', function () {
    config(['networks.networks.litecoin.enabled' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    WithdrawalAddress::factory()->create([
        'user_id' => $owner->id,
        'network' => 'litecoin',
        'address' => 'LQ3BqnhGfQvPZvQ4mZqQ7vQnQvQvQvQvQv',
    ]);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'litecoin', 'amount' => '1.00000000']);
    UsdValuation::factory()->create(['network' => 'litecoin', 'conversion_value' => '100.00']);
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20.00']);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateTransferResources')->once()
        ->with('litecoin', false, 'LQ3BqnhGfQvPZvQ4mZqQ7vQnQvQvQvQvQv')
        ->andReturn(['fee' => '0.00021000', 'energy' => null]);
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'litecoin'])
        ->assertSee('Network fee (up to)')
        ->assertSee('0.00021000 LTC + 20% buffer · miner fee', false)
        ->assertSee('Total fees')
        ->assertDontSee('rented TRON energy')
        ->assertDontSee('Consolidation already incurred')
        ->assertDontSee('Consolidation to fund this withdrawal');
});

test('platform fee note hides when the deposit fee percent is zero', function () {
    $owner = ownerWithTrc20Balance();
    PlatformSettings::instance()->update(['global_deposit_fee_percent' => '0.00']);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateTransferResources')->andReturn(['fee' => '5.00000000', 'energy' => null]);
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Network fee (up to)')
        ->assertDontSee('fee was taken when each deposit was credited');
});

test('sent withdrawal card shows locked fees and reconciliation', function () {
    $owner = ownerWithTrc20Balance('0.00000000');
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '100.00000000',
        'destination_address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
        'network_fee' => '1.98000000',
        'network_fee_native' => '6.00000000',
        'consolidation_fee' => '3.00000000',
        'amount_sent' => '95.02000000',
        'status' => 'sent',
        'tx_hash' => 'abc123',
    ]);
    GasExpense::create([
        'network' => 'usdt_trc20',
        'tx_hash' => 'abc123',
        'amount' => '4.50000000',
        'expensable_type' => Withdrawal::class,
        'expensable_id' => $withdrawal->id,
    ]);
    LedgerEntry::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => '0.57255000',
        'reason' => 'network_fee_adjustment',
        'withdrawal_id' => $withdrawal->id,
    ]);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Withdrawal sent')
        ->assertSee('Amount requested')
        ->assertSee('100.00 USDT')
        ->assertSee('Network fee')
        ->assertSee('1.98 USDT')
        ->assertSee('Consolidation fee')
        ->assertSee('3.00 USDT')
        ->assertSee('Amount sent')
        ->assertSee('95.02 USDT')
        ->assertSee('Actual network cost')
        ->assertSee('4.50000000 TRX')
        ->assertSee('Refund')
        ->assertSee('0.57 USDT');
});

test('withdraw fee estimate is cached for five minutes', function () {
    $owner = ownerWithTrc20Balance();
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateTransferResources')->once()->andReturn(['fee' => '5.00000000', 'energy' => null]);
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)->test(Withdraw::class, ['network' => 'usdt-trc20']);
    Livewire::actingAs($owner)->test(Withdraw::class, ['network' => 'usdt-trc20']);
});

test('withdraw remains available when the fee estimate fails', function () {
    $owner = ownerWithTrc20Balance();
    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateTransferResources')->atLeast()->once()->andThrow(new RuntimeException('signer unavailable'));
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Fee estimate unavailable — the exact fee will be deducted at send time.')
        ->assertSee('Withdraw Full Balance');
});
