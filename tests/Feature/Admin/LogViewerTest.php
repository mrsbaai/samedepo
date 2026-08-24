<?php

use App\Livewire\Admin\LogViewer;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->fakeLogsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'log-viewer-test-'.uniqid();
    File::makeDirectory($this->fakeLogsPath, recursive: true);
    app()->instance('log-viewer.path', $this->fakeLogsPath);
});

afterEach(function () {
    File::deleteDirectory($this->fakeLogsPath);
});

test('non-admins cannot access the log viewer route', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.logs'))
        ->assertForbidden();
});

test('admins can view the log viewer with a list of log files', function () {
    File::put($this->fakeLogsPath.'/laravel.log', "test content\n");
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.logs'))
        ->assertOk()
        ->assertSee('laravel.log', false);
});

test('admins can select a log file and view parsed entries', function () {
    File::put(
        $this->fakeLogsPath.'/laravel.log',
        "[2026-08-10 12:00:00] local.INFO: First entry\n[2026-08-10 12:01:00] local.ERROR: Second entry\n"
    );
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(LogViewer::class)
        ->call('selectFile', 'laravel.log')
        ->assertSet('selectedFile', 'laravel.log')
        ->assertSee('First entry')
        ->assertSee('Second entry')
        ->assertSee('INFO')
        ->assertSee('ERROR');
});

test('entries split channel and level and extract exception class', function () {
    File::put(
        $this->fakeLogsPath.'/laravel.log',
        "[2026-08-10 12:00:00] local.ERROR: RuntimeException: Something went wrong in /app/Service.php:42\n"
    );
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(LogViewer::class)
        ->call('selectFile', 'laravel.log')
        ->assertSee('RuntimeException')
        ->assertSee('Something went wrong')
        ->assertSee('ERROR')
        ->assertDontSee('local.ERROR');
});

test('admins can delete a log file', function () {
    File::put($this->fakeLogsPath.'/old.log', "old content\n");
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(LogViewer::class)
        ->call('deleteFile', 'old.log')
        ->assertSet('selectedFile', null);

    expect(File::exists($this->fakeLogsPath.'/old.log'))->toBeFalse();
});

test('selecting an invalid file is ignored', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(LogViewer::class)
        ->call('selectFile', '../etc/passwd')
        ->assertSet('selectedFile', null);
});

test('deleting the currently viewed file clears the selection', function () {
    File::put($this->fakeLogsPath.'/current.log', "[2026-08-10 12:00:00] local.INFO: Entry\n");
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(LogViewer::class)
        ->call('selectFile', 'current.log')
        ->assertSet('selectedFile', 'current.log')
        ->call('deleteFile', 'current.log')
        ->assertSet('selectedFile', null)
        ->assertSee('No log files found.');
});

test('copying an entry dispatches the clipboard event', function () {
    File::put($this->fakeLogsPath.'/laravel.log', "[2026-08-10 12:00:00] local.INFO: Entry body\n");
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(LogViewer::class)
        ->call('selectFile', 'laravel.log')
        ->call('copyEntry', 0)
        ->assertDispatched('copy-to-clipboard');
});
