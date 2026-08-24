<?php

test('guests can view the api docs page', function () {
    $this->get(route('public.api-docs'))
        ->assertOk()
        ->assertSee('API Documentation')
        ->assertSee('In progress')
        ->assertSee('Back to home');
});

test('the api docs page uses the public layout', function () {
    $this->get(route('public.api-docs'))
        ->assertOk()
        ->assertSee('API Docs')
        ->assertSee('Sign in');
});
