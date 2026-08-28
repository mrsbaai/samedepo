<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;
use App\Services\Blockchain\AddressGenerator;
use Illuminate\Support\Facades\Hash;

function noBaseApiKeyHeader(User $owner): array
{
    $key = 'sm_api_testkey_no_base';
    ApiKey::create([
        'user_id' => $owner->id,
        'name' => 'No Base Test Key',
        'key_hash' => Hash::make($key),
        'status' => 'active',
    ]);

    return ['Authorization' => 'Bearer '.$key];
}

beforeEach(function () {
    $xpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';

    config([
        'blockchain.bitcoin.xpub' => $xpub,
        'blockchain.usdt_trc20.xpub' => $xpub,
        'blockchain.usdt_erc20.xpub' => $xpub,
    ]);
});

test('usdt_base is no longer a supported network', function () {
    $generator = app(AddressGenerator::class);

    expect($generator->networks())->not->toContain('usdt_base');
    expect(fn () => $generator->generate('usdt_base', 1))->toThrow(RuntimeException::class, 'Unsupported network: usdt_base');
});

test('customer registration only provisions the three supported networks', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $response = $this->withHeaders(noBaseApiKeyHeader($owner))
        ->getJson('/api/v1/customers/NO-BASE')
        ->assertCreated();

    $networks = collect($response->json('data.addresses'))->pluck('network')->values()->all();

    expect($networks)->toHaveCount(3)
        ->and($networks)->not->toContain('usdt_base');
});
