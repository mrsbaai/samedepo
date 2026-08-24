<?php

use App\Livewire\Admin\EnvironmentEditor;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

// The editor's file path is rebound to a throwaway temp file for every test in
// this file, so tests never read or write the project's real .env.
beforeEach(function () {
    $this->fakeEnvPath = tempnam(sys_get_temp_dir(), 'env-editor-test-');
    File::put($this->fakeEnvPath, "APP_NAME=Testbed\nAPP_ENV=local\n# a comment\n");
    app()->instance('env-editor.path', $this->fakeEnvPath);
});

afterEach(function () {
    File::delete($this->fakeEnvPath);
});

test('non-admins cannot access the environment editor route', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.environment'))
        ->assertForbidden();
});

test('admins can view the environment editor with a textarea', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.environment'))
        ->assertOk()
        ->assertSee('APP_NAME', false)
        ->assertSee('wire:model="content"', false);
});

test('admin can edit and save the env file, which reapplies the config cache', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Artisan::spy();

    Livewire::actingAs($admin)
        ->test(EnvironmentEditor::class)
        ->set('content', "APP_NAME=Testbed\nAPP_ENV=local\nFORGEOS_TEST_FLAG=hello\n")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('content', fn ($content) => str_contains($content, 'FORGEOS_TEST_FLAG=hello'));

    expect(File::get($this->fakeEnvPath))->toContain('FORGEOS_TEST_FLAG=hello');
    Artisan::shouldHaveReceived('call')->with('config:clear');
    Artisan::shouldHaveReceived('call')->with('config:cache');
});

test('invalid env content is rejected without writing the file', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $original = File::get($this->fakeEnvPath);

    Livewire::actingAs($admin)
        ->test(EnvironmentEditor::class)
        ->set('content', 'not a valid line without equals or hash')
        ->call('save')
        ->assertHasErrors('content');

    expect(File::get($this->fakeEnvPath))->toBe($original);
});

test('cancel reverts unsaved edits back to the saved content', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(EnvironmentEditor::class)
        ->set('content', 'APP_NAME=Changed')
        ->call('cancel')
        ->assertSet('content', "APP_NAME=Testbed\nAPP_ENV=local\n# a comment");
});
