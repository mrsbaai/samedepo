<?php

use App\Livewire\Admin\PlatformSettings;
use App\Models\PlatformSettings as PlatformSettingsModel;
use App\Models\User;
use Livewire\Livewire;

test('an admin can view the platform settings page', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    $this->actingAs($admin)
        ->get(route('admin.platform-settings'))
        ->assertOk()
        ->assertSee('Platform Settings', false)
        ->assertSee('Deposit Fee', false)
        ->assertSee('Minimum Deposits', false)
        ->assertSee('Default Withdrawal Mode', false);
});

test('owners cannot access admin platform settings', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.platform-settings'))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $this->get(route('admin.platform-settings'))->assertRedirect(route('signin'));
});

test('an admin can update the global deposit fee', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('depositFee', '2.5')
        ->call('confirmSaveFee')
        ->call('saveFee')
        ->assertHasNoErrors()
        ->assertSee('samedepo deducts a 2.5% fee', false);

    $this->assertDatabaseHas('platform_settings', ['global_deposit_fee_percent' => 2.5]);
});

test('an admin can update minimum deposit sizes', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('minDepositBitcoin', '0.001')
        ->set('minDepositTrc20', '20')
        ->set('minDepositErc20', '25')
        ->call('confirmSaveMinDeposit')
        ->call('saveMinDeposit')
        ->assertHasNoErrors()
        ->assertSee('Minimum deposit sizes updated', false);

    $this->assertDatabaseHas('platform_settings', [
        'min_deposit_bitcoin' => 0.001,
        'min_deposit_usdt_trc20' => 20,
        'min_deposit_usdt_erc20' => 25,
    ]);
});

test('an admin can update the default withdrawal mode', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance()->update(['default_withdrawal_mode' => 'approval']);

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('defaultWithdrawalMode', 'instant')
        ->call('confirmSaveMode')
        ->call('saveMode')
        ->assertHasNoErrors()
        ->assertSee('Default withdrawal mode set to Instant', false);

    $this->assertDatabaseHas('platform_settings', ['default_withdrawal_mode' => 'instant']);
});

test('platform settings validation rejects invalid deposit fee', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('depositFee', '-1')
        ->call('confirmSaveFee')
        ->call('saveFee')
        ->assertHasErrors(['depositFee']);
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load platform settings')
        ->call('retry')
        ->assertSet('uiState', 'normal');
});
