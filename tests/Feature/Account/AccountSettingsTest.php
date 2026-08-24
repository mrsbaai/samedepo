<?php

use App\Models\User;

it('renders the account settings page for authenticated users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('security'))
        ->assertOk()
        ->assertSee('Security')
        ->assertSee('Email')
        ->assertSee('Password')
        ->assertSee('Two-factor');
});

it('renders the account settings sections in a two-column settings layout', function (): void {
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    $this->actingAs($user)
        ->get(route('security'))
        ->assertOk()
        ->assertSee('Email address', false)
        ->assertSee('New email address', false)
        ->assertSee('Password', false)
        ->assertSee('Current password', false)
        ->assertSee('Two-factor authentication', false)
        ->assertSee('Set up two-factor authentication', false);
});
