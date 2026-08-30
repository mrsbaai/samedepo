<?php

use Illuminate\Support\Facades\Blade;

it('renders the shared dark authentication layout with a muted brand mark', function (): void {
    $html = Blade::render(<<<'BLADE'
<x-authentication.layout title="Sign in">
    <p>Authentication content</p>
</x-authentication.layout>
BLADE);

    expect($html)
        ->toContain('Sign in')
        ->toContain('Authentication content')
        ->toContain('dark:bg-zinc-900')
        ->toContain('data-brand-variant="auth"')
        ->toContain(config('app.name'));
});
