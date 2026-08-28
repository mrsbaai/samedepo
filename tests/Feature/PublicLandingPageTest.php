<?php

use App\Models\User;

test('guests can view the public landing page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Same customer,')
        ->assertSee('same deposit address.')
        ->assertSee('Create a free account')
        ->assertSee('Read the API docs')
        ->assertSee('How it works');
});

test('authenticated users see their role dashboard link and avatar menu', function (bool $isAdmin) {
    $user = User::factory()->create([
        'role' => $isAdmin ? 'admin' : 'owner',
        'is_admin' => $isAdmin,
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee($user->email)
        ->assertSee('Sign out')
        ->assertSee($isAdmin ? 'Overview' : 'Dashboard')
        ->assertSee($isAdmin ? route('admin.dashboard') : route('dashboard'));
})->with([false, true]);

test('the landing page shows supported network icons', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('crypto/bitcoin.svg')
        ->assertSee('crypto/usdt-trc20.svg')
        ->assertSee('crypto/usdt-erc20.svg');
});

test('the landing page explains its network cost and signing technology', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Lower-cost Bitcoin transfers')
        ->assertSee('Automatic USDT gas handling')
        ->assertSee('Isolated transaction signing')
        ->assertSee('native SegWit addresses')
        ->assertSee('ETH, TRX, energy, and bandwidth');
});

test('the landing page shows an accurate customer registration example', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(url('/api/v1/customers'))
        ->assertSee('"reference": "cus_482"', false)
        ->assertSee('"addresses"', false)
        ->assertSee('201 Created')
        ->assertSeeText("# That's it. Really.", false);
});

test('the landing page explains the deposit flow as three connected stages', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Your server registers the customer')
        ->assertSee('samedepo returns permanent addresses')
        ->assertSee('We credit confirmed deposits')
        ->assertSee('You send')
        ->assertSee('You receive');
});

test('the landing page links to sign up and api docs', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('signup'))
        ->assertSee(route('public.api-docs'));
});

test('the landing page shows the FAQs section with managed questions', function () {
    $faq = \App\Models\Faq::create([
        'question' => 'What does the platform fee cover?',
        'answer' => 'There is no monthly or setup cost. We deduct a flat percentage from each confirmed deposit, and withdrawal network fees are shown before and after the transaction.',
        'position' => 1,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('FAQs')
        ->assertSee($faq->question)
        ->assertSee($faq->answer);
});
