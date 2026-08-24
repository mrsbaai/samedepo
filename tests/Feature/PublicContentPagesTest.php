<?php

use App\Models\Faq;
use App\Models\LegalPage;

test('guests can view the privacy page', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('Privacy Policy');
});

test('guests can view the terms page', function () {
    $this->get(route('terms'))
        ->assertOk()
        ->assertSee('Terms of Service');
});

test('guests are redirected from the support page to sign in', function () {
    $this->get(route('support'))->assertRedirect(route('signin'));
});

test('authenticated users can view the support page with ordered FAQs', function () {
    Faq::create(['question' => 'Second question', 'answer' => 'Second answer', 'position' => 2]);
    Faq::create(['question' => 'First question', 'answer' => 'First answer', 'position' => 1]);

    $user = \App\Models\User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('support'))
        ->assertOk();

    $response->assertSeeInOrder(['First question', 'Second question']);
});

test('the privacy page reflects edited content', function () {
    LegalPage::where('slug', 'privacy')->update(['content' => '<p>Custom privacy text.</p>']);

    $this->get(route('privacy'))->assertSee('Custom privacy text.', false);
});
