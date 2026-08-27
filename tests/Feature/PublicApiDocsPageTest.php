<?php

test('guests can view the api docs page with instructions', function () {
    $this->get(route('public.api-docs'))
        ->assertOk()
        ->assertSee('API Documentation')
        ->assertSee('Quick start')
        ->assertSee('Endpoints')
        ->assertSee('Webhooks')
        ->assertSee('Authorization: Bearer')
        ->assertSee('v1/customers')
        ->assertSee('v1/balances')
        ->assertSee('GET')
        ->assertSee('API key');
});

test('the api docs page uses the public layout', function () {
    $this->get(route('public.api-docs'))
        ->assertOk()
        ->assertSee('API Docs')
        ->assertSee('Sign in');
});
