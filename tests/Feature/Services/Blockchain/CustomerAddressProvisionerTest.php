<?php

use App\Models\Customer;
use App\Models\DepositAddress;
use App\Models\User;
use App\Services\Blockchain\CustomerAddressProvisioner;
use App\Support\Network;

beforeEach(function () {
    $xpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';

    config([
        'blockchain.bitcoin.xpub' => $xpub,
        'blockchain.usdt_trc20.xpub' => $xpub,
        'blockchain.usdt_erc20.xpub' => $xpub,
    ]);
});

test('customers of different owners get different derivation indexes and addresses', function () {
    $provisioner = app(CustomerAddressProvisioner::class);

    $ownerA = User::factory()->create(['role' => 'owner']);
    $customerA = Customer::factory()->create(['user_id' => $ownerA->id]);

    $this->actingAs($ownerA);
    $provisioner->provision($customerA);

    $ownerB = User::factory()->create(['role' => 'owner']);
    $customerB = Customer::factory()->create(['user_id' => $ownerB->id]);

    $this->actingAs($ownerB);
    $provisioner->provision($customerB);

    $addressesA = DepositAddress::withoutGlobalScopes()->where('customer_id', $customerA->id)->get();
    $addressesB = DepositAddress::withoutGlobalScopes()->where('customer_id', $customerB->id)->get();

    expect($addressesA)->toHaveCount(count(Network::enabledKeys()));
    expect($addressesB)->toHaveCount(count(Network::enabledKeys()));

    expect($addressesA->pluck('derivation_index')->unique())->toHaveCount(1);
    expect($addressesB->pluck('derivation_index')->unique())->toHaveCount(1);

    $indexA = $addressesA->first()->derivation_index;
    $indexB = $addressesB->first()->derivation_index;
    expect($indexB)->not->toBe($indexA);

    foreach ($addressesB as $row) {
        $rowA = $addressesA->firstWhere('network', $row->network);
        expect($row->address)->not->toBe($rowA->address, "network {$row->network} shares an address");
    }
});
