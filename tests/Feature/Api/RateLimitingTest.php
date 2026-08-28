<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\PlatformSettings;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $xpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';

    config([
        'blockchain.bitcoin.xpub' => $xpub,
        'blockchain.usdt_trc20.xpub' => $xpub,
        'blockchain.usdt_erc20.xpub' => $xpub,
    ]);
});

function rateLimitApiKeyHeader(User $owner): array
{
    $key = 'sm_api_ratelimit_testkey';
    ApiKey::create([
        'user_id' => $owner->id,
        'name' => 'Rate Limit Test Key',
        'key_hash' => Hash::make($key),
        'status' => 'active',
    ]);

    return ['Authorization' => 'Bearer '.$key];
}

function makeRateLimitedApiKeyForOwner(User $owner, string $suffix = 'a'): ApiKey
{
    $key = 'sm_api_ratelimit_testkey_'.$suffix;

    return ApiKey::create([
        'user_id' => $owner->id,
        'name' => 'Rate Limit Test Key '.$suffix,
        'key_hash' => Hash::make($key),
        'status' => 'active',
    ]);
}

function rateLimitHeaderForKey(ApiKey $apiKey, string $plaintext): array
{
    return ['Authorization' => 'Bearer '.$plaintext];
}

test('requests within quota return existing status and rate limit headers', function () {
    PlatformSettings::instance()->update(['api_requests_per_minute' => 2]);
    $owner = User::factory()->create(['role' => 'owner']);
    $headers = rateLimitApiKeyHeader($owner);

    $response = $this->withHeaders($headers)
        ->postJson('/api/v1/customers', ['reference' => 'CUST-001'])
        ->assertCreated();

    $response->assertHeader('X-RateLimit-Limit', '2');
    $response->assertHeader('X-RateLimit-Remaining', '1');
});

test('the first request over quota returns json 429 with retry after and rate limit headers', function () {
    PlatformSettings::instance()->update(['api_requests_per_minute' => 2]);
    $owner = User::factory()->create(['role' => 'owner']);
    $headers = rateLimitApiKeyHeader($owner);

    $this->withHeaders($headers)->postJson('/api/v1/customers', ['reference' => 'CUST-001'])->assertCreated();
    $this->withHeaders($headers)->postJson('/api/v1/customers', ['reference' => 'CUST-002'])->assertCreated();

    $response = $this->withHeaders($headers)
        ->postJson('/api/v1/customers', ['reference' => 'CUST-003'])
        ->assertStatus(429)
        ->assertJson(['message' => 'API rate limit exceeded. Please retry after the time indicated by the Retry-After header.']);

    $response->assertHeader('X-RateLimit-Limit', '2');
    $response->assertHeader('X-RateLimit-Remaining', '0');
    $response->assertHeader('Retry-After');
});

test('rate limit counters are isolated per api key', function () {
    PlatformSettings::instance()->update(['api_requests_per_minute' => 2]);
    $owner = User::factory()->create(['role' => 'owner']);

    $keyA = makeRateLimitedApiKeyForOwner($owner, 'a');
    $keyB = makeRateLimitedApiKeyForOwner($owner, 'b');

    $this->withHeaders(rateLimitHeaderForKey($keyA, 'sm_api_ratelimit_testkey_a'))
        ->postJson('/api/v1/customers', ['reference' => 'CUST-A1'])
        ->assertCreated();
    $this->withHeaders(rateLimitHeaderForKey($keyA, 'sm_api_ratelimit_testkey_a'))
        ->postJson('/api/v1/customers', ['reference' => 'CUST-A2'])
        ->assertCreated();

    $this->withHeaders(rateLimitHeaderForKey($keyA, 'sm_api_ratelimit_testkey_a'))
        ->postJson('/api/v1/customers', ['reference' => 'CUST-A3'])
        ->assertStatus(429);

    $this->withHeaders(rateLimitHeaderForKey($keyB, 'sm_api_ratelimit_testkey_b'))
        ->postJson('/api/v1/customers', ['reference' => 'CUST-B1'])
        ->assertCreated();
});

test('quota resets after the one minute window', function () {
    PlatformSettings::instance()->update(['api_requests_per_minute' => 1]);
    $owner = User::factory()->create(['role' => 'owner']);
    $headers = rateLimitApiKeyHeader($owner);

    $this->withHeaders($headers)
        ->postJson('/api/v1/customers', ['reference' => 'CUST-001'])
        ->assertCreated();

    $this->withHeaders($headers)
        ->postJson('/api/v1/customers', ['reference' => 'CUST-002'])
        ->assertStatus(429);

    $this->travel(61)->seconds();

    $this->withHeaders($headers)
        ->postJson('/api/v1/customers', ['reference' => 'CUST-003'])
        ->assertCreated();
});

test('invalid or revoked credentials do not consume quota or trigger 429', function () {
    PlatformSettings::instance()->update(['api_requests_per_minute' => 1]);
    $owner = User::factory()->create(['role' => 'owner']);

    $this->postJson('/api/v1/customers', ['reference' => 'CUST-001'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthorized.'])
        ->assertHeaderMissing('X-RateLimit-Limit');

    ApiKey::create([
        'user_id' => $owner->id,
        'name' => 'Revoked Key',
        'key_hash' => Hash::make('sm_api_revoked_key'),
        'status' => 'revoked',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer sm_api_revoked_key'])
        ->postJson('/api/v1/customers', ['reference' => 'CUST-001'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthorized.'])
        ->assertHeaderMissing('X-RateLimit-Limit');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/customers', ['reference' => 'CUST-001'])
            ->assertUnauthorized();
    }
});

test('updated admin setting is used for subsequent requests without cache changes', function () {
    PlatformSettings::instance()->update(['api_requests_per_minute' => 2]);
    $owner = User::factory()->create(['role' => 'owner']);
    $apiKey = makeRateLimitedApiKeyForOwner($owner, 'dynamic');
    $headers = rateLimitHeaderForKey($apiKey, 'sm_api_ratelimit_testkey_dynamic');

    $this->withHeaders($headers)->postJson('/api/v1/customers', ['reference' => 'CUST-001'])->assertCreated();
    $this->withHeaders($headers)->postJson('/api/v1/customers', ['reference' => 'CUST-002'])->assertCreated();
    $this->withHeaders($headers)->postJson('/api/v1/customers', ['reference' => 'CUST-003'])->assertStatus(429);

    PlatformSettings::instance()->update(['api_requests_per_minute' => 4]);

    $this->withHeaders($headers)->postJson('/api/v1/customers', ['reference' => 'CUST-004'])->assertCreated();
    $this->withHeaders($headers)->postJson('/api/v1/customers', ['reference' => 'CUST-005'])->assertCreated();
    $this->withHeaders($headers)->postJson('/api/v1/customers', ['reference' => 'CUST-006'])->assertStatus(429);
});
