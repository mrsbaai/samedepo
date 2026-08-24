<?php

use App\Livewire\Dashboard\Withdraw;
use App\Models\Balance;
use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalAddress;
use Livewire\Livewire;

test('an owner can view the withdraw page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    WithdrawalAddress::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
    ]);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => 1000,
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => 1]);

    $this->actingAs($owner)
        ->get(route('withdraw', ['network' => 'usdt-trc20']))
        ->assertOk()
        ->assertSee('Withdraw USDT (TRC20)', false);
});

test('withdraw redirects to withdrawal settings when no address is saved', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => 1000,
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => 1]);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertRedirect(route('withdrawal-settings'));
});

test('ineligible state is shown when balance is below minimum', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    WithdrawalAddress::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
    ]);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => 10,
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => 1]);
    PlatformSettings::instance();

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Below minimum', false)
        ->assertDontSee('Request Withdrawal', false);
});

test('eligible owner can request a full balance withdrawal', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    WithdrawalAddress::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
    ]);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => 1000,
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => 1]);
    PlatformSettings::instance()->update(['default_withdrawal_mode' => 'instant']);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->call('confirmRequest')
        ->call('requestWithdrawal')
        ->assertSet('showRequestModal', false);

    $this->assertDatabaseHas('withdrawals', [
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'pending',
        'gross_amount' => 1000,
        'destination_address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
        'mode' => 'instant',
    ]);

    expect(Balance::where('user_id', $owner->id)->where('network', 'usdt_trc20')->first()->amount)
        ->toBe('0.00000000');
});

test('a pending withdrawal can be cancelled', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    WithdrawalAddress::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
    ]);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => 0,
    ]);
    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'pending',
        'gross_amount' => 500,
        'destination_address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
    ]);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->call('confirmCancel')
        ->call('cancelWithdrawal')
        ->assertSet('showCancelModal', false);

    expect(Withdrawal::first()->status)->toBe('cancelled');
    expect(Balance::first()->amount)->toBe('500.00000000');
});

test('request is hidden while a withdrawal is pending', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    WithdrawalAddress::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
    ]);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => 0,
    ]);
    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'pending',
        'gross_amount' => 500,
    ]);

    Livewire::actingAs($owner)
        ->test(Withdraw::class, ['network' => 'usdt-trc20'])
        ->assertSee('Pending withdrawal', false)
        ->assertDontSee('Request Withdrawal', false);
});

test('guests are redirected to signin', function () {
    $this->get(route('withdraw', ['network' => 'usdt-trc20']))->assertRedirect(route('signin'));
});

test('admins cannot access withdraw page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('withdraw', ['network' => 'usdt-trc20']))
        ->assertForbidden();
});
