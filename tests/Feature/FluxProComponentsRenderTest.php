<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    view()->share('errors', new ViewErrorBag);
});

test('flux pro components render without errors', function () {
    $components = [
        'flux:accordion',
        'flux:calendar',
        'flux:chart',
        'flux:date-picker',
        'flux:editor',
        'flux:kanban',
        'flux:pillbox',
        'flux:select',
        'flux:toast',
    ];

    foreach ($components as $component) {
        $html = Blade::render("<{$component}></{$component}>");

        expect($html)->not->toBeEmpty("{$component} rendered empty markup");
    }
});

test('flux pro select renders options correctly', function () {
    $html = Blade::render(<<<'BLADE'
<flux:select wire:model="role" variant="listbox">
    <flux:select.option value="admin">Admin</flux:select.option>
    <flux:select.option value="user">User</flux:select.option>
</flux:select>
BLADE);

    expect($html)
        ->toContain('Admin')
        ->toContain('User');
});

test('flux pro kanban renders columns and cards', function () {
    $html = Blade::render(<<<'BLADE'
<flux:kanban>
    <flux:kanban.column>
        <flux:kanban.column.header>Todo</flux:kanban.column.header>
        <flux:kanban.card>Task 1</flux:kanban.card>
    </flux:kanban.column>
</flux:kanban>
BLADE);

    expect($html)
        ->toContain('Todo')
        ->toContain('Task 1');
});
