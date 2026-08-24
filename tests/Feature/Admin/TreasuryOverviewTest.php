<?php

use App\Livewire\Admin\TreasuryOverview;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;

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
