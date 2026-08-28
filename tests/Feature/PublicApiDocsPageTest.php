<?php

use App\Models\PlatformSettings;

test('guests can view the api docs page with instructions', function () {
    PlatformSettings::instance()->update(['api_requests_per_minute' => 60]);

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
        ->assertSee('API key')
        ->assertSee('Rate limits')
        ->assertSee('60 requests per minute')
        ->assertSee('429 Too Many Requests')
        ->assertSee('Retry-After')
        ->assertSee('status')
        ->assertSee('created')
        ->assertSee('existing')
        ->assertSee('credited_usd_value')
        ->assertSee('credited_amount')
        ->assertSee('X-RateLimit-Limit')
        ->assertSee('X-RateLimit-Remaining');
});

test('the api docs page uses the public layout', function () {
    $this->get(route('public.api-docs'))
        ->assertOk()
        ->assertSee('API Docs')
        ->assertSee('Sign in');
});
