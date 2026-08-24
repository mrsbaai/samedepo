<?php

use App\Models\User;

test('user dashboard renders shared layout with user navigation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-flux-sidebar-toggle', false)
        ->assertSee('data-flux-navbar', false)
        ->assertSee('Home', false)
        ->assertSee('Signed in as', false)
        ->assertSee('Security', false)
        ->assertSee('Sign out', false);
});

test('admin dashboard renders shared layout with admin-only navigation', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('data-flux-sidebar-toggle', false)
        ->assertSee('data-flux-navbar', false)
        ->assertSee('Overview', false)
        ->assertSee('Environment', false)
        ->assertSee('Logs', false)
        ->assertSee('Signed in as', false)
        ->assertSee('Security', false)
        ->assertSee('Sign out', false)
        ->assertDontSee('Home', false);
});

test('non-admin users cannot access the admin dashboard', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});
