<?php

use App\Livewire\Admin\SupportSettings;
use App\Livewire\Support\TicketThread;
use App\Models\SupportIdentity;
use App\Models\SupportSetting;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('non-admins cannot access the settings route', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.support.settings'))
        ->assertForbidden();
});

test('settings page lists all support identities', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.support.settings'))
        ->assertOk()
        ->assertSee('Support')
        ->assertSee('Sales')
        ->assertSee('Management')
        ->assertSee('Administration');
});

test('admin can update a support identity name and avatar', function () {
    Storage::fake('public');
    Storage::disk('public')->put('support-agents/test-avatar.png', 'fake-image');

    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->call('openIdentityModal', 'support')
        ->set('editingName', 'Jordan')
        ->call('selectAvatar', 'support-agents/test-avatar.png')
        ->call('saveIdentity')
        ->assertHasNoErrors();

    $identity = SupportIdentity::forRole('support');

    expect($identity->name)->toBe('Jordan');
    expect($identity->avatar)->toBe('support-agents/test-avatar.png');
});

test('admin can upload a custom avatar image for an identity', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->call('openIdentityModal', 'support')
        ->set('editingName', 'Taylor')
        ->set('uploadedAvatar', UploadedFile::fake()->image('custom-avatar.png'))
        ->call('saveIdentity')
        ->assertHasNoErrors();

    $identity = SupportIdentity::forRole('support');

    expect($identity->name)->toBe('Taylor');
    expect($identity->avatar)->toStartWith('support-agents/');
    Storage::disk('public')->assertExists($identity->avatar);
});

test('admin can choose an avatar and it appears on the ticket thread', function () {
    Storage::fake('public');
    Storage::disk('public')->put('support-agents/test-avatar.png', 'fake-image');

    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->call('openIdentityModal', 'support')
        ->call('selectAvatar', 'support-agents/test-avatar.png')
        ->call('saveIdentity')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'Reply')
        ->call('reply')
        ->assertHasNoErrors();

    $url = Storage::disk('public')->url('support-agents/test-avatar.png');

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee($url, false);
});

test('the administration identity avatar updates the admin profile picture', function () {
    Storage::fake('public');
    Storage::disk('public')->put('support-agents/admin-avatar.png', 'fake-image');

    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->call('openIdentityModal', 'administration')
        ->call('selectAvatar', 'support-agents/admin-avatar.png')
        ->call('saveIdentity')
        ->assertHasNoErrors();

    $url = Storage::disk('public')->url('support-agents/admin-avatar.png');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee($url, false);
});

test('a message keeps the support avatar that was current when it was sent', function () {
    Storage::fake('public');
    Storage::disk('public')->put('support-agents/old-avatar.png', 'fake-image');
    Storage::disk('public')->put('support-agents/new-avatar.png', 'fake-image');

    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->call('openIdentityModal', 'support')
        ->call('selectAvatar', 'support-agents/old-avatar.png')
        ->call('saveIdentity')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'First reply')
        ->call('reply')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->call('openIdentityModal', 'support')
        ->call('selectAvatar', 'support-agents/new-avatar.png')
        ->call('saveIdentity')
        ->assertHasNoErrors();

    $oldUrl = Storage::disk('public')->url('support-agents/old-avatar.png');
    $newUrl = Storage::disk('public')->url('support-agents/new-avatar.png');

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee($oldUrl, false)
        ->assertDontSee($newUrl, false);
});

test('admin can reply with different identities', function () {
    Storage::fake('public');
    Storage::disk('public')->put('support-agents/support-avatar.png', 'fake-image');
    Storage::disk('public')->put('support-agents/sales-avatar.png', 'fake-image');

    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->call('openIdentityModal', 'support')
        ->set('editingName', 'Alex')
        ->call('selectAvatar', 'support-agents/support-avatar.png')
        ->call('saveIdentity')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->call('openIdentityModal', 'sales')
        ->set('editingName', 'Sam')
        ->call('selectAvatar', 'support-agents/sales-avatar.png')
        ->call('saveIdentity')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('identityRole', 'sales')
        ->set('body', 'Sales reply')
        ->call('reply')
        ->assertHasNoErrors();

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Sam from Sales')
        ->assertSee(Storage::disk('public')->url('support-agents/sales-avatar.png'), false);
});

test('admin can save special instructions for AI replies', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->set('specialInstructions', 'Feature X is currently disabled.')
        ->call('saveSpecialInstructions')
        ->assertHasNoErrors();

    $setting = SupportSetting::current();

    expect($setting->special_instructions)->toBe('Feature X is currently disabled.');
});

test('admin can save service context for AI replies', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(SupportSettings::class)
        ->set('serviceDescription', 'Simple invoicing for freelancers.')
        ->set('serviceUseCase', 'Create invoices, track payments, download reports.')
        ->call('saveServiceContext')
        ->assertHasNoErrors();

    $setting = SupportSetting::current();

    expect($setting->service_description)->toBe('Simple invoicing for freelancers.')
        ->and($setting->service_use_case)->toBe('Create invoices, track payments, download reports.');
});

test('empty identity name falls back to role label', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'Reply')
        ->call('reply')
        ->assertHasNoErrors();

    $this->actingAs($user)
        ->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Support')
        ->assertDontSee('from Support');
});
