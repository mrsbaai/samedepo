<?php

use App\Livewire\Admin\WebsiteOwnerDetail;
use App\Models\User;
use Livewire\Livewire;

test('an admin can view a website owner detail', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create([
        'role' => 'owner',
        'withdrawal_mode' => 'instant',
        'deposit_fee_override' => 0.75,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.owners.show', $owner))
        ->assertOk()
        ->assertSee($owner->email)
        ->assertSee('Instant')
        ->assertSee('0.75%');
});

test('an admin can change an owner withdrawal mode', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'withdrawal_mode' => 'instant']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwnerDetail::class, ['owner' => $owner->id])
        ->set('withdrawalMode', 'approval')
        ->call('confirmSaveMode')
        ->call('saveMode')
        ->assertHasNoErrors()
        ->assertSee('Withdrawal mode updated to Administrator Approval', false);

    expect($owner->fresh()->withdrawal_mode)->toBe('approval');
});

test('an admin can set a deposit fee override', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwnerDetail::class, ['owner' => $owner->id])
        ->set('feeOverride', '2.5')
        ->call('confirmSaveFee')
        ->call('saveFee')
        ->assertHasNoErrors()
        ->assertSee('Deposit fee override set to 2.5%', false);

    expect($owner->fresh()->deposit_fee_override)->toBe('2.50');
});

test('an admin can clear a deposit fee override', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'deposit_fee_override' => 1.5]);

    Livewire::actingAs($admin)
        ->test(WebsiteOwnerDetail::class, ['owner' => $owner->id])
        ->set('feeOverride', '')
        ->call('confirmSaveFee')
        ->call('saveFee')
        ->assertHasNoErrors()
        ->assertSee('Fee override removed', false);

    expect($owner->fresh()->deposit_fee_override)->toBeNull();
});

test('non-existent owner shows not found', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.owners.show', 99999))
        ->assertOk()
        ->assertSee('Owner not found')
        ->assertSee('Back to Owners');
});

test('owners cannot access owner detail', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $other = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.owners.show', $other))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->get(route('admin.owners.show', $owner))->assertRedirect(route('signin'));
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwnerDetail::class, ['owner' => $owner->id])
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load website owner')
        ->call('retry')
        ->assertSet('uiState', 'normal');
});
