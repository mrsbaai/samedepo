<?php

use App\Services\Blockchain\Providers\BlockCypherProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('it uses the standard address endpoint and parses confirmed and unconfirmed incoming references', function () {
    Http::fake(function (Request $request) {
        expect($request->url())->toContain('/addrs/1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa?')
            ->and($request->url())->not->toContain('/full')
            ->and($request->url())->toContain('token=test-token');

        return Http::response([
            'txrefs' => [
                ['tx_hash' => 'abc123', 'confirmations' => 2, 'tx_input_n' => -1, 'value' => 150000000],
                ['tx_hash' => 'spent-output', 'confirmations' => 4, 'tx_input_n' => 0, 'value' => 90000000],
            ],
            'unconfirmed_txrefs' => [
                ['tx_hash' => 'pending123', 'tx_input_n' => -1, 'value' => 50000000],
            ],
        ]);
    });

    $provider = new BlockCypherProvider('bitcoin', 'btc', 'test-token');
    $transactions = $provider->fetchTransactions(['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa']);

    expect($transactions)->toHaveCount(2)
        ->and($transactions[0]->txHash)->toBe('abc123')
        ->and($transactions[0]->amount)->toBe('1.50000000')
        ->and($transactions[0]->confirmations)->toBe(2)
        ->and($transactions[1]->txHash)->toBe('pending123')
        ->and($transactions[1]->amount)->toBe('0.50000000')
        ->and($transactions[1]->confirmations)->toBe(0);
});

test('it safely skips malformed and outgoing transaction references', function () {
    Http::fake([
        'https://api.blockcypher.com/*' => Http::response([
            'txrefs' => 'invalid',
            'unconfirmed_txrefs' => [
                null,
                ['tx_hash' => '', 'tx_input_n' => -1, 'value' => 100],
                ['tx_hash' => 'outgoing', 'tx_input_n' => 1, 'value' => 100],
                ['tx_hash' => 'zero', 'tx_input_n' => -1, 'value' => 0],
            ],
        ]),
    ]);

    $transactions = (new BlockCypherProvider('bitcoin', 'btc'))->fetchTransactions(['btc-address']);

    expect($transactions)->toBe([]);
});

test('it combines multiple incoming outputs from the same transaction', function () {
    Http::fake([
        'https://api.blockcypher.com/*' => Http::response([
            'txrefs' => [
                ['tx_hash' => 'same-hash', 'confirmations' => 3, 'tx_input_n' => -1, 'value' => 10000000],
                ['tx_hash' => 'same-hash', 'confirmations' => 3, 'tx_input_n' => -1, 'value' => 20000000],
            ],
        ]),
    ]);

    $transactions = (new BlockCypherProvider('bitcoin', 'btc'))->fetchTransactions(['btc-address']);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->amount)->toBe('0.30000000')
        ->and($transactions[0]->confirmations)->toBe(3);
});
