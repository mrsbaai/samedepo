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
        ->set('minimums.bitcoin', '150')
        ->set('minimums.usdt_trc20', '200')
        ->set('minimums.usdt_erc20', '250')
        ->call('confirmSave')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Withdrawal minimums updated', false);

    $this->assertDatabaseHas('network_settings', [
        'network' => 'bitcoin',
        'withdrawal_min_usd' => 150,
    ]);
    $this->assertDatabaseHas('network_settings', [
        'network' => 'usdt_trc20',
        'withdrawal_min_usd' => 200,
    ]);
    $this->assertDatabaseHas('network_settings', [
        'network' => 'usdt_erc20',
        'withdrawal_min_usd' => 250,
    ]);
});

test('withdrawal minimums must be greater than zero', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettings::instance();

    Livewire::actingAs($admin)
        ->test(WithdrawalSettings::class)
        ->set('minimums.bitcoin', '0')
        ->set('minimums.usdt_trc20', '0')
        ->set('minimums.usdt_erc20', '0')
        ->call('confirmSave')
        ->call('save')
        ->assertHasErrors(['minimums.bitcoin', 'minimums.usdt_trc20', 'minimums.usdt_erc20'])
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
