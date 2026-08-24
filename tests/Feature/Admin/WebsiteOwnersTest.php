<?php

use App\Livewire\Admin\WebsiteOwners;
use App\Models\User;
use Livewire\Livewire;

test('an admin can view the website owners list', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'email' => 'owner@example.com']);

    $this->actingAs($admin)
        ->get(route('admin.owners'))
        ->assertOk()
        ->assertSee('Website Owners')
        ->assertSee($owner->email);
});

test('owner list shows withdrawal mode and fee override', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    User::factory()->create([
        'role' => 'owner',
        'withdrawal_mode' => 'instant',
        'deposit_fee_override' => 0.5,
    ]);
    User::factory()->create([
        'role' => 'owner',
        'withdrawal_mode' => 'approval',
        'deposit_fee_override' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.owners'))
        ->assertOk()
        ->assertSee('Instant')
        ->assertSee('Administrator Approval')
        ->assertSee('0.50%')
        ->assertSee('Platform default');
});

test('owners can be searched by email', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    User::factory()->create(['role' => 'owner', 'email' => 'alice@shop.com']);
    User::factory()->create(['role' => 'owner', 'email' => 'bob@shop.com']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwners::class)
        ->set('search', 'alice')
        ->assertSee('alice@shop.com')
        ->assertDontSee('bob@shop.com');
});

test('owners cannot access the admin owners list', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.owners'))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $this->get(route('admin.owners'))->assertRedirect(route('signin'));
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WebsiteOwners::class)
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load website owners')
        ->call('retry')
        ->assertSet('uiState', 'normal');
});
