<?php

use App\Models\User;

test('owner role users can access the dashboard', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk();
});

test('admin role users cannot access the owner dashboard', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertForbidden();
});

test('guests are redirected to sign in when visiting the dashboard', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('signin'));
});
