<?php

use App\Models\User;

test('welcome page defaults to dark mode', function (): void {
    $response = $this->get('/');

    $response->assertOk();
    expect($response->getContent())->toMatch('/<html[^>]*class="dark"[^>]*>/');
});

test('sign-in page defaults to dark mode', function (): void {
    $response = $this->get(route('signin'));

    $response->assertOk();
    expect($response->getContent())->toMatch('/<html[^>]*class="dark"[^>]*>/');
});

test('dashboard defaults to dark mode', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    expect($response->getContent())->toMatch('/<html[^>]*class="dark"[^>]*>/');
});

test('admin dashboard defaults to dark mode', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertOk();
    expect($response->getContent())->toMatch('/<html[^>]*class="dark"[^>]*>/');
});

test('the appearance switcher and appearance script are not rendered', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    expect($response->getContent())
        ->not->toContain('applyAppearance')
        ->not->toContain('flux.appearance');
});
