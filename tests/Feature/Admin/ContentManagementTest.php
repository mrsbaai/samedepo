<?php

use App\Livewire\Admin\ContentManagement;
use App\Models\FaqsContent;
use App\Models\LegalPage;
use App\Models\User;
use Livewire\Livewire;

test('an admin can view the content management page', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.content'))
        ->assertOk()
        ->assertSee('Admin Content Management')
        ->assertSee('Terms of Service')
        ->assertSee('Privacy Policy')
        ->assertSee('FAQs');
});

test('an admin can update the terms of service content', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ContentManagement::class)
        ->set('termsContent', '<p>Updated terms.</p>')
        ->call('confirmSaveTerms')
        ->call('saveTerms')
        ->assertHasNoErrors()
        ->assertSee('Terms of Service saved.');

    expect(LegalPage::query()->where('slug', 'terms')->first()->content)->toBe('<p>Updated terms.</p>');
});

test('an admin can update the privacy policy content', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ContentManagement::class)
        ->set('privacyContent', '<p>Updated privacy policy.</p>')
        ->call('confirmSavePrivacy')
        ->call('savePrivacy')
        ->assertHasNoErrors()
        ->assertSee('Privacy Policy saved.');

    expect(LegalPage::query()->where('slug', 'privacy')->first()->content)->toBe('<p>Updated privacy policy.</p>');
});

test('an admin can update the faqs content', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ContentManagement::class)
        ->set('faqsContent', '<p>Updated FAQs.</p>')
        ->call('confirmSaveFaqs')
        ->call('saveFaqs')
        ->assertHasNoErrors()
        ->assertSee('FAQs saved.');

    expect(FaqsContent::first()->content)->toBe('<p>Updated FAQs.</p>');
});

test('owners cannot access the content management page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.content'))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $this->get(route('admin.content'))->assertRedirect(route('signin'));
});
