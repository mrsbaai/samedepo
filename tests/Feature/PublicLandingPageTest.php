<?php

use App\Models\PlatformSettings;

test('guests can view the public landing page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Same customer,')
        ->assertSee('same deposit address.')
        ->assertSee('Create a free account')
        ->assertSee('Read the API docs')
        ->assertSee('How it works');
});

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
