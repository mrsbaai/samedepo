<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;

test('an api key belongs to an owner', function () {
    $key = ApiKey::factory()->create();

    expect($key->user)->toBeInstanceOf(User::class);
});

test('api keys are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();

    ApiKey::create([
        'user_id' => $owner->id,
        'name' => 'Owner key',
        'key_hash' => 'owner-hash',
        'status' => 'active',
    ]);
    ApiKey::create([
        'user_id' => $otherOwner->id,
        'name' => 'Other key',
        'key_hash' => 'other-hash',
        'status' => 'active',
    ]);

    $this->actingAs($owner);

    expect(ApiKey::pluck('key_hash')->toArray())->toBe(['owner-hash']);
});
