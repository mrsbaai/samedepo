<?php

use App\Livewire\Dashboard\WithdrawalSettings;
use App\Models\User;
use App\Models\WithdrawalAddress;
use Livewire\Livewire;

test('an owner can view the withdrawal settings page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('withdrawal-settings'))
        ->assertOk()
        ->assertSee('Withdrawal Settings', false)
        ->assertSee('crypto/bitcoin.svg', false)
        ->assertSee('crypto/usdt-trc20.svg', false)
        ->assertSee('crypto/usdt-erc20.svg', false)
        ->assertDontSee('img/crypto/', false);
});

test('an owner can set a withdrawal address', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(WithdrawalSettings::class)
        ->call('startEdit', 'usdt_trc20', '')
        ->set('editingAddress', 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA')
        ->call('confirmSave')
        ->call('saveAddress')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('withdrawal_addresses', [
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'address' => 'TN2xQz5vGbR9eqAFfbGZvFvgkhLGc4f2sA',
    ]);
});

test('an owner can update a withdrawal address', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    WithdrawalAddress::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'address' => 'bc1qold',
    ]);

    Livewire::actingAs($owner)
        ->test(WithdrawalSettings::class)
        ->call('startEdit', 'bitcoin', 'bc1qold')
        ->set('editingAddress', 'bc1qnew')
        ->call('confirmSave')
        ->call('saveAddress')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('withdrawal_addresses', [
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'address' => 'bc1qnew',
    ]);
});

test('a withdrawal address is required', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(WithdrawalSettings::class)
        ->call('startEdit', 'usdt_trc20', '')
        ->set('editingAddress', '')
        ->call('confirmSave')
        ->call('saveAddress')
        ->assertHasErrors(['editingAddress' => 'required']);
});

test('error state renders a callout and retry resets to normal', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(WithdrawalSettings::class)
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load withdrawal settings')
        ->call('retry')
        ->assertSet('uiState', 'normal');
});

test('guests are redirected to signin', function () {
    $this->get(route('withdrawal-settings'))->assertRedirect(route('signin'));
});

test('admins cannot access withdrawal settings', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('withdrawal-settings'))
        ->assertForbidden();
});
