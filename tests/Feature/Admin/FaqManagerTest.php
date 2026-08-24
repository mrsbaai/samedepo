<?php

use App\Livewire\Admin\FaqManager;
use App\Models\Faq;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('non-admins cannot access the FAQ manager route', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.faqs'))
        ->assertForbidden();
});

test('admin can create a FAQ', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(FaqManager::class)
        ->set('question', 'How do I reset my password?')
        ->set('answer', 'Use the forgot password link on the sign-in page.')
        ->call('create')
        ->assertHasNoErrors();

    expect(Faq::where('question', 'How do I reset my password?')->exists())->toBeTrue();
});

test('admin can edit and delete a FAQ', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $faq = Faq::create(['question' => 'Original', 'answer' => 'Original answer', 'position' => 1]);

    Livewire::actingAs($admin)
        ->test(FaqManager::class)
        ->call('startEdit', $faq->id)
        ->set('editQuestion', 'Updated')
        ->set('editAnswer', 'Updated answer')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($faq->refresh()->question)->toBe('Updated');

    Livewire::actingAs($admin)
        ->test(FaqManager::class)
        ->call('delete', $faq->id);

    expect(Faq::find($faq->id))->toBeNull();
});

test('admin can attach an image to a FAQ when creating it', function () {
    Storage::fake('public');
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(FaqManager::class)
        ->set('question', 'How do I upload a file?')
        ->set('answer', 'Use the attach button.')
        ->set('image', UploadedFile::fake()->image('screenshot.png'))
        ->call('create')
        ->assertHasNoErrors();

    $faq = Faq::where('question', 'How do I upload a file?')->firstOrFail();

    expect($faq->image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($faq->image_path);
});

test('admin can remove an existing FAQ image while editing', function () {
    Storage::fake('public');
    $admin = User::factory()->create(['is_admin' => true]);
    $faq = Faq::create(['question' => 'Q', 'answer' => 'A', 'position' => 1, 'image_path' => 'faqs/existing.png']);

    Livewire::actingAs($admin)
        ->test(FaqManager::class)
        ->call('startEdit', $faq->id)
        ->set('editQuestion', 'Q')
        ->set('editAnswer', 'A')
        ->set('removeEditImage', true)
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($faq->refresh()->image_path)->toBeNull();
});

test('admin can replace an existing FAQ image while editing', function () {
    Storage::fake('public');
    $admin = User::factory()->create(['is_admin' => true]);
    $faq = Faq::create(['question' => 'Q', 'answer' => 'A', 'position' => 1, 'image_path' => 'faqs/existing.png']);

    Livewire::actingAs($admin)
        ->test(FaqManager::class)
        ->call('startEdit', $faq->id)
        ->set('editImage', UploadedFile::fake()->image('new.png'))
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($faq->refresh()->image_path)->not->toBe('faqs/existing.png');
    Storage::disk('public')->assertExists($faq->image_path);
});

test('the support page shows the FAQ image with an expand button', function () {
    $user = User::factory()->create();
    Faq::create(['question' => 'With image', 'answer' => 'See below', 'position' => 1, 'image_path' => 'faqs/example.png']);

    $this->actingAs($user)
        ->get(route('support'))
        ->assertOk()
        ->assertSee('View full image')
        ->assertSee('data-flux-modal', false);
});

test('admin can reorder FAQs', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $first = Faq::create(['question' => 'First', 'answer' => 'A', 'position' => 1]);
    $second = Faq::create(['question' => 'Second', 'answer' => 'B', 'position' => 2]);

    Livewire::actingAs($admin)
        ->test(FaqManager::class)
        ->call('moveUp', $second->id);

    expect($first->refresh()->position)->toBe(2)
        ->and($second->refresh()->position)->toBe(1);
});
