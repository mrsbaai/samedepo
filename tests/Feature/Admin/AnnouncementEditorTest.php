<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Admin\AnnouncementEditor;
use App\Models\Announcement;
use App\Models\User;
use Livewire\Livewire;

test('non-admins cannot access the announcement editor route', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.announcement'))
        ->assertForbidden();
});

test('admin can save an announcement, which is then shown on the user dashboard', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(AnnouncementEditor::class)
        ->set('content', '<p>We will be doing maintenance this weekend.</p>')
        ->call('save')
        ->assertHasNoErrors();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('We will be doing maintenance this weekend.');
});

test('admin can remove the announcement, which then disappears from the dashboard', function () {
    Announcement::create(['content' => '<p>Old announcement</p>']);
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(AnnouncementEditor::class)
        ->call('remove');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Old announcement');
});

test('the dashboard shows no announcement modal when none is set', function () {
    $user = User::factory()->create();

    // Note: the Livewire snapshot always serializes the `showAnnouncement` property name,
    // so we assert on the rendered modal markup rather than the bare word "Announcement".
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-flux-modal', false);
});

test('the announcement modal is closable', function () {
    Announcement::create(['content' => '<p>Heads up!</p>']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-flux-modal', false)
        ->assertSee('Got it');
});

test('the announcement shows on any authenticated page, not only the dashboard', function () {
    Announcement::create(['content' => '<p>Heads up!</p>']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('security'))
        ->assertOk()
        ->assertSee('Heads up!');
});

test('the announcement only shows once, on whichever page is visited first', function () {
    Announcement::create(['content' => '<p>Heads up!</p>']);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Heads up!');

    $this->actingAs($user)->get(route('security'))
        ->assertOk()
        ->assertDontSee('Heads up!');

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Heads up!');
});

test('signing in again shows the announcement once more', function () {
    Announcement::create(['content' => '<p>Heads up!</p>']);
    $user = User::factory()->create();

    session(['announcement_seen' => true]);

    event(new AuthenticationEvent(type: AuthenticationEvent::USER_SIGNED_IN, user: $user));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Heads up!');
});
