<?php

declare(strict_types=1);

use App\Livewire\Admin\TreasuryOverview;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;

test('admin treasury dashboard shows per-network balances and totals', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $wallet = TreasuryWallet::factory()->create([
        'network' => 'bitcoin',
        'address' => '1Abc',
        'available_funds' => '2.00000000',
        'native_balance' => '0.50000000',
    ]);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '100.000000']);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('1Abc')
        ->assertSee('mempool.space')
        ->assertSee('2.00000000 BTC')
        ->assertSee('$200.00');
});

test('treasury dashboard shows unswept deposits and address count', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);

    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 0]);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '100.000000']);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.50000000',
        'status' => 'credited',
        'credited_at' => now(),
        'swept_at' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('1.50000000')
        ->assertSee('1 address');
});

test('treasury dashboard shows pending withdrawals in network snapshot', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 0]);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '100.000000']);

    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '2.00000000',
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertSee('Network snapshot')
        ->assertSee('2.00000000')
        ->assertSee('1 pending');
});

test('profit summary footer is shown on the treasury dashboard', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create([
        'network' => 'bitcoin',
        'available_funds' => '1.00000000',
        'native_balance' => '0.10000000',
    ]);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '100.000000']);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertSee('Withdrawable now:')
        ->assertSee('Total profit:')
        ->assertSee('$100.00 USD');
});

test('existing gas policy sections still render and function', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20']);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.usdt_erc20.reserve_threshold', '0.03000000')
        ->set('policies.usdt_erc20.top_up_amount', '0.04000000')
        ->set('policies.usdt_erc20.max_top_up', '0.20000000')
        ->set('policies.usdt_erc20.alert_cooldown', 120)
        ->call('savePolicy', 'usdt_erc20')
        ->assertHasNoErrors();
});

test('payout modal rejects an amount larger than available funds', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => '1.00000000']);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->call('openPayout', 'bitcoin')
        ->set('payoutDestination', '1A...')
        ->set('payoutAmount', '2.00000000')
        ->call('previewPayout')
        ->assertHasErrors('payoutAmount');
});

test('payout happy path creates and broadcasts a payout', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'derivation_index' => 0, 'address' => 'treasury-btc', 'available_funds' => '5.00000000']);
    PlatformSettings::instance()->update(['profit_address_bitcoin' => '1BoatSLRHtKNngkdXEeobR76b53LETtpyT']);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '100.000000']);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->andReturn('0.00100000');
    $broadcaster->shouldReceive('broadcastPayout')->andReturn('payout-tx-123');
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->call('openPayout', 'bitcoin')
        ->assertSet('payoutDestination', '1BoatSLRHtKNngkdXEeobR76b53LETtpyT')
        ->set('payoutAmount', '1.50000000')
        ->call('previewPayout')
        ->call('confirmPayout')
        ->assertSet('payoutStep', 'success')
        ->assertSee('payout-tx-123');

    $payout = TreasuryPayout::query()->first();
    expect($payout)->not->toBeNull()
        ->and($payout->status)->toBe('sent')
        ->and($payout->tx_hash)->toBe('payout-tx-123')
        ->and($payout->destination_address)->toBe('1BoatSLRHtKNngkdXEeobR76b53LETtpyT');
});
