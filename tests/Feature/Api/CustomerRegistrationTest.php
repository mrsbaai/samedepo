<?php

use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\DepositAddress;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $xpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';

    config([
        'blockchain.bitcoin.xpub' => $xpub,
        'blockchain.usdt_trc20.xpub' => $xpub,
        'blockchain.usdt_erc20.xpub' => $xpub,
    ]);
});

function apiKeyHeader(User $owner): array
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

test('an owner can register a customer and receives three deposit addresses', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $response = $this->withHeaders(apiKeyHeader($owner))
        ->postJson('/api/v1/customers', ['reference' => 'CUST-ABC123'])
        ->assertCreated();

    $response->assertJsonPath('status', 'created');
    $response->assertJsonPath('data.customer_reference', 'CUST-ABC123');
    expect($response->json('data.addresses'))->toHaveCount(3);

    $addresses = collect($response->json('data.addresses'));
    expect($addresses->where('network', 'bitcoin')->first()['address'])->toStartWith('1');
    expect($addresses->where('network', 'usdt_erc20')->first()['address'])->toStartWith('0x');
    expect($addresses->where('network', 'usdt_trc20')->first()['address'])->toStartWith('T');

    $customer = Customer::query()
        ->where('user_id', $owner->id)
        ->where('customer_reference', 'CUST-ABC123')
        ->first();
    expect($customer)->not->toBeNull();
    expect($customer->depositAddresses)->toHaveCount(3);
});

test('registering the same customer reference returns existing addresses', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $first = $this->withHeaders(apiKeyHeader($owner))
        ->postJson('/api/v1/customers', ['reference' => 'CUST-ABC123'])
        ->assertCreated()
        ->assertJsonPath('status', 'created')
        ->json('data.addresses');

    $second = $this->withHeaders(apiKeyHeader($owner))
        ->postJson('/api/v1/customers', ['reference' => 'CUST-ABC123'])
        ->assertOk()
        ->assertJsonPath('status', 'existing')
        ->json('data.addresses');

    expect($second)->toHaveCount(3);
    expect($second)->toEqual($first);

    expect(Customer::query()
        ->where('user_id', $owner->id)
        ->where('customer_reference', 'CUST-ABC123')
        ->count())
        ->toBe(1);
});

test('an owner can retrieve an existing customer by reference', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'customer_reference' => 'CUST-ABC123',
    ]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
    ]);

    $response = $this->withHeaders(apiKeyHeader($owner))
        ->getJson('/api/v1/customers/CUST-ABC123')
        ->assertOk()
        ->assertJsonPath('data.customer_reference', 'CUST-ABC123')
        ->assertJsonCount(1, 'data.addresses');

    expect($response->json())->not->toHaveKey('status');
});

test('requests without a valid api key are unauthorized', function () {
    $this->postJson('/api/v1/customers', ['reference' => 'CUST-ABC123'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthorized.']);

    $this->getJson('/api/v1/customers/CUST-ABC123')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthorized.']);
});

test('invalid input returns json validation errors', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->withHeaders(apiKeyHeader($owner))
        ->postJson('/api/v1/customers', ['reference' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reference']);
});

test('an owner cannot retrieve another owners customer', function () {
    $ownerA = User::factory()->create(['role' => 'owner']);
    $ownerB = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create([
        'user_id' => $ownerA->id,
        'customer_reference' => 'CUST-ABC123',
    ]);

    $this->withHeaders(apiKeyHeader($ownerB))
        ->getJson('/api/v1/customers/CUST-ABC123')
        ->assertNotFound();
});
