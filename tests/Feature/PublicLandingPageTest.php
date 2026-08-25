<?php

use App\Models\PlatformSettings;
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

test('the landing page links to sign up and api docs', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('signup'))
        ->assertSee(route('public.api-docs'));
});

test('the landing page discloses the platform fee', function () {
    PlatformSettings::instance(); // ensure singleton exists with default fee

    $this->get('/')
        ->assertOk()
        ->assertSee('Free for website owners')
        ->assertSee('flat percentage');
});
