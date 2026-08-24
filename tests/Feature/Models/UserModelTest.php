<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;

test('new users default to the owner role', function () {
    $user = User::factory()->create();

    expect($user->fresh()->role)->toBe('owner');
});

test('users can be created with the admin role', function () {
    $user = User::factory()->create(['role' => 'admin']);

    expect($user->role)->toBe('admin');
});

test('an owner has many customers', function () {
    $owner = User::factory()->create();
    Customer::create([
        'user_id' => $owner->id,
        'customer_reference' => 'CUST-001',
    ]);

    expect($owner->customers)->toHaveCount(1)
        ->and($owner->customers->first()->customer_reference)->toBe('CUST-001');
});
