<?php

use App\Livewire\Admin\TreasuryOverview;
use App\Models\GasPolicy;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Services\Blockchain\GasTreasuryService;

test('an admin can view treasury wallet balances', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create([
        'network' => 'bitcoin',
        'available_funds' => 2.3451,
    ]);
    UsdValuation::factory()->create([
        'network' => 'bitcoin',
        'conversion_value' => 30000.00,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Treasury')
        ->assertSee('Bitcoin')
        ->assertSee('2.34510000 BTC')
        ->assertSee('$70,353.00');
});

test('treasury overview shows a card for each provisioned network', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 1]);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'available_funds' => 100]);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'available_funds' => 200]);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Bitcoin')
        ->assertSee('USDT (TRC20)')
        ->assertSee('USDT (ERC20)');
});

test('empty state is shown when no treasury wallets are provisioned', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Treasury wallets have not been provisioned yet.');
});

test('owners cannot access the treasury overview', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.treasury'))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $this->get(route('admin.treasury'))->assertRedirect(route('signin'));
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('uiState', 'error')
        ->assertSee("Couldn't load treasury data")
        ->call('retry')
        ->assertSet('uiState', 'normal');
});

test('an admin can edit gas policy pause and refresh a wallet', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_erc20']);
    $service = Mockery::mock(GasTreasuryService::class);
    $service->shouldReceive('policy')->with('usdt_erc20')->andReturnUsing(
        fn () => GasPolicy::firstOrCreate(['network' => 'usdt_erc20'], [
            'reserve_threshold' => '0.05000000',
            'top_up_amount' => '0.02000000',
            'max_top_up' => '0.10000000',
            'alert_cooldown' => 60,
        ]),
    );
    $service->shouldReceive('refreshTreasuryWallet')->once()->andReturnUsing(function (TreasuryWallet $wallet) {
        $wallet->update(['native_balance' => '0.25000000', 'refreshed_at' => now()]);

        return ['native_balance' => '0.25000000'];
    });
    app()->instance(GasTreasuryService::class, $service);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.usdt_erc20.reserve_threshold', '0.03000000')
        ->set('policies.usdt_erc20.top_up_amount', '0.04000000')
        ->set('policies.usdt_erc20.max_top_up', '0.20000000')
        ->set('policies.usdt_erc20.alert_cooldown', 120)
        ->call('savePolicy', 'usdt_erc20')
        ->call('togglePause', 'usdt_erc20')
        ->call('refreshWallet', $wallet->id)
        ->assertSee('0.25000000');

    expect(GasPolicy::where('network', 'usdt_erc20')->first())
        ->reserve_threshold->toBe('0.03000000')
        ->manual_paused->toBeTrue()
        ->alert_cooldown->toBe(120);
});

test('gas policy rejects invalid limits', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20']);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.usdt_trc20.top_up_amount', '200')
        ->set('policies.usdt_trc20.max_top_up', '100')
        ->call('savePolicy', 'usdt_trc20')
        ->assertHasErrors('policies.usdt_trc20.top_up_amount');
});
