<?php

use App\Models\User;

test('a regular user sees a Support link in the account dropdown', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Support');
});

test('an admin does not see a Support link in the account dropdown', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee(route('support'), false);
});
