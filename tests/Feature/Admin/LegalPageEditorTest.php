<?php

use App\Livewire\Admin\LegalPageEditor;
use App\Models\LegalPage;
use App\Models\User;
use Livewire\Livewire;

test('non-admins cannot access the legal page editor route', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.legal.edit', 'privacy'))
        ->assertForbidden();
});

test('admin can update the privacy policy content', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(LegalPageEditor::class, ['slug' => 'privacy'])
        ->set('content', '<p>Updated privacy content.</p>')
        ->call('save')
        ->assertHasNoErrors();

    expect(LegalPage::where('slug', 'privacy')->first()->content)
        ->toBe('<p>Updated privacy content.</p>');
});
