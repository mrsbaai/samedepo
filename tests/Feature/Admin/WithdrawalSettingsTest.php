<?php

use App\Livewire\Admin\WithdrawalSettings;
use App\Models\PlatformSettings;
use App\Models\User;
use Livewire\Livewire;

test('an admin can view the withdrawal settings page', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettings::instance();

    $this->actingAs($admin)
        ->get(route('admin.withdrawal-settings'))
        ->assertOk()
        ->assertSee('Withdrawal Minimums', false)
        ->assertSee('Minimum Amount (USD)', false);
});

test('owners cannot access admin withdrawal settings', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.withdrawal-settings'))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $this->get(route('admin.withdrawal-settings'))->assertRedirect(route('signin'));
});

test('an admin can update withdrawal minimums', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettings::instance();

    Livewire::actingAs($admin)
        ->test(WithdrawalSettings::class)
        ->set('minBitcoin', '150')
        ->set('minTrc20', '200')
        ->set('minErc20', '250')
        ->call('confirmSave')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Withdrawal minimums updated', false);

    $this->assertDatabaseHas('platform_settings', [
        'withdrawal_min_usd_bitcoin' => 150,
        'withdrawal_min_usd_usdt_trc20' => 200,
        'withdrawal_min_usd_usdt_erc20' => 250,
    ]);
});

test('withdrawal minimums must be greater than zero', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettings::instance();

    Livewire::actingAs($admin)
        ->test(WithdrawalSettings::class)
        ->set('minBitcoin', '0')
        ->set('minTrc20', '0')
        ->set('minErc20', '0')
        ->call('confirmSave')
        ->call('save')
        ->assertHasErrors(['minBitcoin', 'minTrc20', 'minErc20'])
        ->assertSee('USD withdrawal minimum must be greater than $0.', false);
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WithdrawalSettings::class)
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load withdrawal settings')
        ->call('retry')
        ->assertSet('uiState', 'normal');
});
