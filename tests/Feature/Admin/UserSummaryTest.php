<?php

use App\Models\User;

test('non-admins cannot view a user summary', function () {
    $viewer = User::factory()->create(['is_admin' => false]);
    $user = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('admin.users.summary', $user))
        ->assertForbidden();
});

test('admins can view a user summary with human-readable dates', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create([
        'email' => 'target@example.test',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.users.summary', $user))
        ->assertOk()
        ->assertSee('target@example.test')
        ->assertSee($user->created_at->diffForHumans())
        ->assertSee('Active');
});

test('user summary shows inactive status', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($admin)
        ->get(route('admin.users.summary', $user))
        ->assertOk()
        ->assertSee('Inactive');
});

test('markdown export returns exact dates and is only accessible to admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create([
        'email' => 'target@example.test',
        'is_active' => true,
        'created_at' => '2025-01-15 10:30:00',
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.users.summary.markdown', $user))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

    $content = $response->getContent();
    expect($content)
        ->toContain('target@example.test')
        ->toContain('2025-01-15 10:30:00')
        ->toContain('Active');
});

test('non-admins cannot access the markdown export', function () {
    $viewer = User::factory()->create(['is_admin' => false]);
    $user = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('admin.users.summary.markdown', $user))
        ->assertForbidden();
});
