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
        ->assertSee('Default Withdrawal Mode', false)
        ->assertSee('API Request Limit', false);
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

test('an admin can update the api request limit', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance()->update(['global_deposit_fee_percent' => 2.5]);

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('apiRequestsPerMinute', '120')
        ->call('confirmSaveApiRequests')
        ->call('saveApiRequests')
        ->assertHasNoErrors()
        ->assertSee('API request limit updated', false);

    $this->assertDatabaseHas('platform_settings', ['api_requests_per_minute' => 120]);

    $settings = PlatformSettingsModel::instance();
    expect($settings->global_deposit_fee_percent)->toBe('2.50');
});

test('platform settings validation rejects invalid api request limit values', function ($value) {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('apiRequestsPerMinute', (string) $value)
        ->call('confirmSaveApiRequests')
        ->call('saveApiRequests')
        ->assertHasErrors(['apiRequestsPerMinute']);
})->with([
    'zero' => 0,
    'negative' => -10,
    'fractional' => 10.5,
    'non-numeric' => 'abc',
]);

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load platform settings')
        ->call('retry')
        ->assertSet('uiState', 'normal');
});

test('an admin sees the profit payouts card with current values', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance()->update([
        'profit_address_bitcoin' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
        'profit_address_usdt_trc20' => 'T'.str_repeat('1', 33),
        'profit_address_usdt_erc20' => '0x'.str_repeat('a', 40),
        'profit_payout_warn_fee_percent' => 1.50,
        'profit_payout_block_fee_percent' => 4.00,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.platform-settings'))
        ->assertOk()
        ->assertSee('Profit payouts', false)
        ->assertSee('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', false)
        ->assertSee('T'.str_repeat('1', 33), false)
        ->assertSee('0x'.str_repeat('a', 40), false)
        ->assertSee('1.50', false)
        ->assertSee('4.00', false);
});

test('an admin can update profit payout settings', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('profitAddressBitcoin', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa')
        ->set('profitAddressUsdtTrc20', 'T'.str_repeat('1', 33))
        ->set('profitAddressUsdtErc20', '0x'.str_repeat('a', 40))
        ->set('profitWarnFeePercent', '1.5')
        ->set('profitBlockFeePercent', '4.0')
        ->call('confirmSaveProfit')
        ->call('saveProfit')
        ->assertHasNoErrors()
        ->assertSee('Profit payout settings saved.', false);

    $this->assertDatabaseHas('platform_settings', [
        'profit_address_bitcoin' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
        'profit_address_usdt_trc20' => 'T'.str_repeat('1', 33),
        'profit_address_usdt_erc20' => '0x'.str_repeat('a', 40),
        'profit_payout_warn_fee_percent' => 1.50,
        'profit_payout_block_fee_percent' => 4.00,
    ]);
});

test('profit payout settings reject an invalid tron address', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('profitAddressUsdtTrc20', 'not-a-tron-address')
        ->call('confirmSaveProfit')
        ->call('saveProfit')
        ->assertHasErrors(['profitAddressUsdtTrc20'])
        ->assertSeeText("This doesn't look like a valid USDT (TRC20) address.");
});

test('profit payout settings reject a warning threshold above or equal to the block threshold', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('profitWarnFeePercent', '6')
        ->set('profitBlockFeePercent', '5')
        ->call('confirmSaveProfit')
        ->call('saveProfit')
        ->assertHasErrors(['profitWarnFeePercent'])
        ->assertSeeText('Warning threshold must be lower than the block threshold.');

    $this->assertDatabaseHas('platform_settings', [
        'profit_payout_warn_fee_percent' => 1.00,
        'profit_payout_block_fee_percent' => 5.00,
    ]);
});

test('empty profit payout address clears the saved address', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance()->update([
        'profit_address_usdt_trc20' => 'T'.str_repeat('1', 33),
    ]);

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('profitAddressUsdtTrc20', '')
        ->set('profitWarnFeePercent', '1.5')
        ->set('profitBlockFeePercent', '4.0')
        ->call('confirmSaveProfit')
        ->call('saveProfit')
        ->assertHasNoErrors()
        ->assertSee('Profit payout settings saved.', false);

    $this->assertDatabaseHas('platform_settings', ['profit_address_usdt_trc20' => null]);
});

test('saving profit payout settings does not change unrelated platform settings', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance()->update(['global_deposit_fee_percent' => 3.5]);

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('profitAddressBitcoin', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa')
        ->set('profitWarnFeePercent', '1.5')
        ->set('profitBlockFeePercent', '4.0')
        ->call('confirmSaveProfit')
        ->call('saveProfit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('platform_settings', ['global_deposit_fee_percent' => 3.5]);
});
