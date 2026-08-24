<?php

use App\Services\Blockchain\AddressGenerator;

beforeEach(function () {
    $this->testXpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';

    config([
        'blockchain.bitcoin.xpub' => $this->testXpub,
        'blockchain.usdt_trc20.xpub' => $this->testXpub,
        'blockchain.usdt_erc20.xpub' => $this->testXpub,
    ]);
});

test('it generates a bitcoin address', function () {
    $address = app(AddressGenerator::class)->generate('bitcoin', 0);

    expect($address)->toStartWith('1');
    expect(strlen($address))->toBeGreaterThan(25);
});

test('it generates an ethereum address', function () {
    $address = app(AddressGenerator::class)->generate('usdt_erc20', 0);

    expect($address)->toStartWith('0x');
    expect(strlen($address))->toBe(42);
});

test('it generates a tron address', function () {
    $address = app(AddressGenerator::class)->generate('usdt_trc20', 0);

    expect($address)->toStartWith('T');
    expect(strlen($address))->toBeGreaterThan(30);
});

test('the same index produces the same address', function () {
    $generator = app(AddressGenerator::class);

    expect($generator->generate('bitcoin', 7))
        ->toBe($generator->generate('bitcoin', 7));
});

test('different indexes produce different addresses', function () {
    $generator = app(AddressGenerator::class);

    expect($generator->generate('bitcoin', 0))
        ->not->toBe($generator->generate('bitcoin', 1));
});

test('it throws when the xpub is missing', function () {
    config(['blockchain.bitcoin.xpub' => null]);

    app(AddressGenerator::class)->generate('bitcoin', 0);
})->throws(RuntimeException::class, 'Missing extended public key configuration for network: bitcoin');

test('it throws for unsupported networks', function () {
    app(AddressGenerator::class)->generate('solana', 0);
})->throws(RuntimeException::class, 'Unsupported network: solana');
