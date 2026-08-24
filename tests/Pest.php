<?php

uses(
    Tests\DuskTestCase::class,
    // Illuminate\Foundation\Testing\DatabaseMigrations::class,
)->in('Browser');

// NOTE: Each `uses()->in(...)` call below maps ONE base test case to ONE directory.
// Do not add a second `uses(\Tests\DuskTestCase::class)->in('Browser')` block (or any
// other duplicate mapping) — Pest errors with "Test case ... can not be used" if the
// same directory is mapped more than once. `php artisan dusk:install` should not need
// to touch this file; if it (or any generator) proposes a second Dusk/Browser mapping,
// remove the duplicate instead of keeping both.

uses(\Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Integration');

uses(\Tests\TestCase::class)
    ->in('Unit');

uses(\Tests\DuskTestCase::class)
    ->in('Browser');
