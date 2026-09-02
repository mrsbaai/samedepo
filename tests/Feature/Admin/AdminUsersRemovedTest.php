<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('admin users routes no longer exist', function () {
    expect(Route::has('admin.users'))->toBeFalse()
        ->and(Route::has('admin.users.summary'))->toBeFalse()
        ->and(Route::has('admin.users.summary.markdown'))->toBeFalse();

    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)->get('/admin/users')->assertNotFound();
    $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('/admin/users', false);
});
