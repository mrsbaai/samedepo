<?php

use App\Models\User;
use Livewire\Livewire;

it('toggles from dark to light and persists the preference', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('appearance-switcher')
        ->assertSet('appearance', 'dark')
        ->call('toggle')
        ->assertSet('appearance', 'light');

    expect($user->fresh()->appearance)->toBe('light');
});

it('toggles from light back to dark', function (): void {
    $user = User::factory()->create(['appearance' => 'light']);

    Livewire::actingAs($user)
        ->test('appearance-switcher')
        ->assertSet('appearance', 'light')
        ->call('toggle')
        ->assertSet('appearance', 'dark');

    expect($user->fresh()->appearance)->toBe('dark');
});
