<?php

use App\Models\Customer;
use App\Models\DepositAddress;
use App\Models\User;
use App\Support\Network;

beforeEach(function () {
    $xpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';

    config([
        'blockchain.bitcoin.xpub' => $xpub,
        'blockchain.usdt_trc20.xpub' => $xpub,
        'blockchain.usdt_erc20.xpub' => $xpub,
        'networks.networks.ethereum.enabled' => true,
        'networks.networks.usdc_erc20.enabled' => true,
    ]);
});

test('the command backfills missing deposit addresses reusing the customer derivation index', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    $evmAddress = '0x'.str_repeat('a', 40);
    foreach (['bitcoin' => 'btc-address', 'usdt_trc20' => 'tron-address', 'usdt_erc20' => $evmAddress] as $network => $address) {
        DepositAddress::factory()->create([
            'customer_id' => $customer->id,
            'network' => $network,
            'address' => $address,
            'derivation_index' => 7,
        ]);
    }

    $this->artisan('app:backfill-deposit-addresses')
        ->expectsOutputToContain("customer {$customer->customer_reference}: +2")
        ->expectsOutputToContain('Created 2 addresses for 1 customers.')
        ->assertSuccessful();

    $customer->load('depositAddresses');
    expect($customer->depositAddresses)->toHaveCount(count(Network::enabledKeys()));

    $new = $customer->depositAddresses->whereNotIn('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20']);
    expect($new->pluck('network')->sort()->values()->all())->toBe(['ethereum', 'usdc_erc20']);
    expect($new->pluck('derivation_index')->unique()->values()->all())->toBe([7]);
    expect($new->pluck('address')->unique()->values()->all())->toBe([$evmAddress]);

    $this->artisan('app:backfill-deposit-addresses')
        ->expectsOutputToContain('Created 0 addresses for 1 customers.')
        ->assertSuccessful();

    expect(DepositAddress::where('customer_id', $customer->id)->count())->toBe(count(Network::enabledKeys()));
});

test('a customer that already has every enabled network gets no new rows', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    foreach (Network::enabledKeys() as $network) {
        DepositAddress::factory()->create([
            'customer_id' => $customer->id,
            'network' => $network,
            'derivation_index' => 3,
        ]);
    }

    $this->artisan('app:backfill-deposit-addresses')
        ->expectsOutputToContain('Created 0 addresses')
        ->assertSuccessful();

    expect(DepositAddress::where('customer_id', $customer->id)->count())->toBe(count(Network::enabledKeys()));
});
