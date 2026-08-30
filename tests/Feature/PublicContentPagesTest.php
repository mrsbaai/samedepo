<?php

use App\Models\Faq;
use App\Models\LegalPage;
use App\Models\User;

test('guests see privacy and terms links in the public footer', function () {
    $this->get(route('public.landing'))
        ->assertOk()
        ->assertSee(route('privacy'))
        ->assertSee(route('terms'));
});

test('authenticated users see privacy and terms links in the public footer', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('public.landing'))
        ->assertOk()
        ->assertSee(route('privacy'))
        ->assertSee(route('terms'));
});

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

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('support'))
        ->assertOk();

    $response->assertSeeInOrder(['First question', 'Second question']);
});

test('FAQ answers render safe anchor tags as Flux links', function () {
    Faq::create([
        'question' => 'Where are the current limits?',
        'answer' => 'See <a href="https://samedepo.com/api-docs?tab=limits">current fees and limits</a>. <script>alert("unsafe")</script>',
        'position' => 1,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('support'))
        ->assertOk()
        ->assertSee('href="https://samedepo.com/api-docs?tab=limits"', false)
        ->assertSee('current fees and limits')
        ->assertDontSee('<script>alert("unsafe")</script>', false);
});

test('the privacy page reflects edited content', function () {
    LegalPage::query()->updateOrCreate(
        ['slug' => 'privacy'],
        ['title' => 'Privacy Policy', 'content' => '<p>Custom privacy text.</p>']
    );

    $this->get(route('privacy'))->assertSee('Custom privacy text.', false);
});

test('the terms page reflects edited content', function () {
    LegalPage::query()->updateOrCreate(
        ['slug' => 'terms'],
        ['title' => 'Terms of Service', 'content' => '<p>Custom terms text.</p>']
    );

    $this->get(route('terms'))->assertSee('Custom terms text.', false);
});
