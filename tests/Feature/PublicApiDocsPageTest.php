<?php

use App\Models\PlatformSettings;
use App\Models\User;

test('guests can view the api docs page with instructions', function () {
    PlatformSettings::instance()->update([
        'global_deposit_fee_percent' => 1.25,
        'min_deposit_bitcoin' => 0.00025000,
        'min_deposit_usdt_trc20' => 12.50000000,
        'min_deposit_usdt_erc20' => 15.00000000,
        'api_requests_per_minute' => 60,
    ]);

    $this->get(route('public.api-docs'))
        ->assertOk()
        ->assertSee('API Documentation')
        ->assertSee('Quick start')
        ->assertSee('Endpoints')
        ->assertSee('Webhooks')
        ->assertSee('Limits & fees', false)
        ->assertSee('1.25%')
        ->assertSee('0.00025000 BTC')
        ->assertSee('12.50 USDT')
        ->assertSee('15.00 USDT')
        ->assertSeeText('These are the current standard values.')
        ->assertSeeText('Higher-volume accounts can request custom limits')
        ->assertSee('Authorization: Bearer')
        ->assertSee('v1/customers')
        ->assertSee('v1/balances')
        ->assertSee('GET')
        ->assertSee('API key')
        ->assertSee('API request limit')
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

test('the limits tab can be opened directly with a query string', function () {
    $response = $this->get(route('public.api-docs', ['tab' => 'limits']))->assertOk();

    expect($response->getContent())
        ->toMatch('/data-selected="data-selected" selected="selected" name="limits"/')
        ->not->toMatch('/data-selected="data-selected" selected="selected" name="quick-start"/');
});

test('owners see their effective deposit fee in the api docs', function () {
    PlatformSettings::instance()->update(['global_deposit_fee_percent' => 1.25]);
    $owner = User::factory()->create(['role' => 'owner', 'deposit_fee_override' => 0.75]);

    $this->actingAs($owner)
        ->get(route('public.api-docs'))
        ->assertOk()
        ->assertSee('0.75%')
        ->assertSee("Your account's deposit fee", false)
        ->assertSee('These are the live values for your account')
        ->assertSee('Ask support')
        ->assertDontSee('1.25%');
});

test('the standalone limits and fees route has been removed', function () {
    $this->get('/limits-and-fees')->assertNotFound();
});

test('the api docs page uses the public layout', function () {
    $this->get(route('public.api-docs'))
        ->assertOk()
        ->assertSee('API Docs')
        ->assertSee('Sign in');
});
