<?php

use App\Models\ApiKey;
use App\Models\Balance;
use App\Models\UsdValuation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function balanceApiKeyHeader(User $owner): array
{
    $key = 'sm_api_testkey123456789';
    ApiKey::create([
        'user_id' => $owner->id,
        'name' => 'Test Key',
        'key_hash' => Hash::make($key),
        'status' => 'active',
    ]);

    return ['Authorization' => 'Bearer '.$key];
}

test('an owner can view all balances with estimated usd values', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'amount' => 0.5,
    ]);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => 100,
    ]);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_erc20',
        'amount' => 200,
    ]);
    UsdValuation::factory()->create([
        'network' => 'bitcoin',
        'conversion_value' => 30000.00,
    ]);
    UsdValuation::factory()->create([
        'network' => 'usdt_trc20',
        'conversion_value' => 1.00,
    ]);
    UsdValuation::factory()->create([
        'network' => 'usdt_erc20',
        'conversion_value' => 1.00,
    ]);

    $response = $this->withHeaders(balanceApiKeyHeader($owner))
        ->getJson('/api/v1/balances')
        ->assertOk();

    $response->assertJsonCount(3, 'data.balances');
    $response->assertJsonPath('data.total_usd', 15300);
    $response->assertJsonPath('data.balances.0.network', 'Bitcoin');
    $response->assertJsonPath('data.balances.0.amount', 0.5);
    $response->assertJsonPath('data.balances.0.usd_value', 15000);
});

test('missing usd valuation returns zero usd value for that network', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'amount' => 0.5,
    ]);

    $this->withHeaders(balanceApiKeyHeader($owner))
        ->getJson('/api/v1/balances')
        ->assertOk()
        ->assertJsonPath('data.balances.0.usd_value', 0)
        ->assertJsonPath('data.total_usd', 0);
});

test('requests without a valid api key are unauthorized', function () {
    $this->getJson('/api/v1/balances')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthorized.']);
});

test('an owner only sees their own balances', function () {
    $ownerA = User::factory()->create(['role' => 'owner']);
    $ownerB = User::factory()->create(['role' => 'owner']);
    Balance::factory()->create([
        'user_id' => $ownerA->id,
        'network' => 'bitcoin',
        'amount' => 0.5,
    ]);
    Balance::factory()->create([
        'user_id' => $ownerB->id,
        'network' => 'bitcoin',
        'amount' => 1.5,
    ]);

    $this->withHeaders(balanceApiKeyHeader($ownerA))
        ->getJson('/api/v1/balances')
        ->assertOk()
        ->assertJsonPath('data.balances.0.amount', 0.5);
});
