<?php

declare(strict_types=1);

use App\Livewire\Admin\TreasuryOverview;
use App\Models\GasPolicy;
use App\Models\TreasuryWallet;
use App\Models\User;

test('the trc20 policy form exposes and validates the rental fields', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0, 'address' => 'TTreasury']);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0, 'address' => '0xTreasury']);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Energy mode')
        ->assertSee('policies.usdt_trc20.rent_max_price_sun', false)
        ->assertDontSee('policies.usdt_erc20.energy_mode', false)
        ->assertDontSee('policies.usdt_erc20.rent_duration_sec', false);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.usdt_trc20.energy_mode', 'rent')
        ->set('policies.usdt_trc20.rent_max_price_sun', 90)
        ->set('policies.usdt_trc20.rent_duration_sec', 3600)
        ->set('policies.usdt_trc20.rent_float_alert_trx', '20')
        ->call('savePolicy', 'usdt_trc20')
        ->assertHasNoErrors();

    expect(GasPolicy::where('network', 'usdt_trc20')->first())
        ->energy_mode->toBe('rent')
        ->rent_max_price_sun->toBe(90)
        ->rent_duration_sec->toBe(3600)
        ->rent_float_alert_trx->toBe('20.00000000');

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.usdt_trc20.rent_duration_sec', 60)
        ->call('savePolicy', 'usdt_trc20')
        ->assertHasErrors(['policies.usdt_trc20.rent_duration_sec']);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->set('policies.usdt_trc20.energy_mode', 'bogus')
        ->call('savePolicy', 'usdt_trc20')
        ->assertHasErrors(['policies.usdt_trc20.energy_mode']);
});
