<?php

use App\Models\User;
use App\Security\Models\ForbiddenEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('unauthorized owner accessing admin is recorded as a forbidden event', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)->get('/admin/platform-settings')->assertStatus(403);

    $this->assertDatabaseHas('forbidden_events', [
        'source' => 'ensure_admin',
        'reason' => 'Admin role required',
        'path' => '/admin/platform-settings',
        'method' => 'GET',
    ]);
});

test('threat detector blocks and records a forbidden event linked to threat_events', function () {
    config(['security.enabled' => true]);

    $this->call('GET', '/signin?x='.urlencode('<script>alert(1)</script>'), [], [], [], ['REMOTE_ADDR' => '192.168.1.1'])->assertStatus(403);

    $this->assertDatabaseHas('forbidden_events', [
        'source' => 'threat_detector',
        'path' => '/signin',
        'method' => 'GET',
    ]);

    $this->assertTrue(ForbiddenEvent::query()->whereNotNull('threat_event_id')->exists());
});

test('admin can view the forbidden log and filter by source', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $admin = User::factory()->create(['role' => 'owner', 'is_admin' => true]);

    $this->actingAs($owner)->get('/admin/platform-settings')->assertStatus(403);

    $this->actingAs($admin)
        ->get('/admin/security/forbidden-log')
        ->assertOk()
        ->assertSee('Forbidden Log')
        ->assertSee('ensure_admin');

    $this->actingAs($admin)
        ->get('/admin/security/forbidden-log?source=ensure_admin')
        ->assertOk();

    $this->actingAs($admin)
        ->get('/admin/security/forbidden-log?source=threat_detector')
        ->assertOk();
});
