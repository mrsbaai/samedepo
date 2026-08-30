<?php

use App\Models\User;

test('owner dashboard renders shared layout with owner navigation', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-flux-sidebar-toggle', false)
        ->assertSee('data-brand-variant="authenticated"', false)
        ->assertSee('data-flux-navbar', false)
        ->assertSee('data-flux-sidebar', false)
        ->assertSee('Dashboard', false)
        ->assertSee('Customers', false)
        ->assertSee('Deposits', false)
        ->assertSee('Transaction History', false)
        ->assertSee('API Documentation', false)
        ->assertSee(route('public.api-docs'), false)
        ->assertSee('Settings', false)
        ->assertSee('API Keys', false)
        ->assertSee('Webhook Settings', false)
        ->assertSee('Withdrawal Settings', false)
        ->assertSee('Signed in as', false)
        ->assertSee($owner->email, false)
        ->assertSee('Security', false)
        ->assertSee('Sign out', false);
});

test('admin dashboard renders shared layout with admin-only navigation', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('data-flux-sidebar-toggle', false)
        ->assertSee('data-brand-variant="authenticated"', false)
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
