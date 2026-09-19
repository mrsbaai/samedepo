<?php

declare(strict_types=1);

use App\Livewire\Admin\TreasuryOverview;
use App\Models\GasPolicy;
use App\Models\TreasuryWallet;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('the trc20 policy form exposes and validates the rental fields', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0, 'address' => 'TTreasury']);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0, 'address' => '0xTreasury']);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Energy mode')
        ->assertSee('policies.native_trx.rent_max_price_sun', false)
        ->assertDontSee('policies.native_eth.energy_mode', false)
        ->assertDontSee('policies.native_eth.rent_duration_sec', false);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.native_trx.energy_mode', 'rent')
        ->set('policies.native_trx.rent_max_price_sun', 90)
        ->set('policies.native_trx.rent_duration_sec', 3600)
        ->set('policies.native_trx.rent_float_alert_trx', '20')
        ->call('savePolicy', 'native_trx')
        ->assertHasNoErrors();

    expect(GasPolicy::where('network', 'native_trx')->first())
        ->energy_mode->toBe('rent')
        ->rent_max_price_sun->toBe(90)
        ->rent_duration_sec->toBe(3600)
        ->rent_float_alert_trx->toBe('20.00000000');

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.native_trx.rent_duration_sec', 60)
        ->call('savePolicy', 'native_trx')
        ->assertHasErrors(['policies.native_trx.rent_duration_sec']);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.native_trx.energy_mode', 'bogus')
        ->call('savePolicy', 'native_trx')
        ->assertHasErrors(['policies.native_trx.energy_mode']);
});

test('the treasury page renders the energy float in rent mode and saves back to burn', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0, 'address' => 'TTreasury']);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'energy_mode' => 'rent',
        'rent_max_price_sun' => 90,
        'rent_duration_sec' => 3600,
        'rent_float_alert_trx' => '20.00000000',
    ]);
    Http::fake(['*' => Http::response([
        'error' => false,
        'data' => ['balance' => '50000000', 'depositAddress' => 'TRefillAddr'],
    ], 200)]);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('TronSave float')
        ->assertSee('TRefillAddr')
        ->assertSee('50.00000000')
        ->assertSee('Energy mode')
        ->assertSee('Max unit price (sun)')
        ->assertSee('Rent duration (sec)')
        ->assertSee('Float alert (TRX)');

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.native_trx.energy_mode', 'burn')
        ->call('savePolicy', 'native_trx')
        ->assertHasNoErrors();

    expect(GasPolicy::where('network', 'native_trx')->value('energy_mode'))->toBe('burn');
});
