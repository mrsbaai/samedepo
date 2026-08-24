<?php

use App\Livewire\Admin\UserSearch;
use App\Models\User;
use Livewire\Livewire;

test('non-admins cannot access the user search route', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.users'))
        ->assertForbidden();
});

test('admins can view the user search page', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSee('User Search');
});

test('search filters users by email', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $matching = User::factory()->create(['email' => 'found@example.test']);
    User::factory()->create(['email' => 'other@example.test']);

    Livewire::actingAs($admin)
        ->test(UserSearch::class)
        ->set('query', 'found')
        ->assertSee('found@example.test')
        ->assertDontSee('other@example.test');
});

test('empty search shows all users', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserSearch::class)
        ->assertSee($user->email);
});

test('search results link to the user summary page', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSee(route('admin.users.summary', $user), false);
});
