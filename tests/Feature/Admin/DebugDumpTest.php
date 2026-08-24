<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

test('dump environment editor page html', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)->get(route('admin.environment'));

    File::put(base_path('debug_env_editor.html'), $response->getContent());

    expect(true)->toBeTrue();
});
