<?php

use App\Services\Blockchain\Providers\BlockCypherProvider;
use Illuminate\Support\Facades\Http;

test('it fetches bitcoin transactions for watched addresses', function () {
    Http::fake([
        'https://api.blockcypher.com/v1/btc/main/addrs/1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa/full*' => Http::response([
            'txs' => [
                [
                    'hash' => 'abc123',
                    'confirmations' => 2,
                    'outputs' => [
                        ['addresses' => ['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'], 'value' => 150000000],
                        ['addresses' => ['other'], 'value' => 50000000],
                    ],
                ],
            ],
        ]),
    ]);

    $provider = new BlockCypherProvider('bitcoin', 'btc', 'test-token');
    $transactions = $provider->fetchTransactions(['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa']);

    expect($transactions)->toHaveCount(1);
    expect($transactions[0]->txHash)->toBe('abc123');
    expect($transactions[0]->amount)->toBe('1.50000000');
    expect($transactions[0]->confirmations)->toBe(2);
});

test('it omits transactions with no outputs to the watched address', function () {
    Http::fake([
        'https://api.blockcypher.com/v1/btc/main/addrs/1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa/full*' => Http::response([
            'txs' => [
                [
                    'hash' => 'no-match',
                    'confirmations' => 5,
                    'outputs' => [
                        ['addresses' => ['other-address'], 'value' => 100000000],
                    ],
                ],
            ],
        ]),
    ]);

    $provider = new BlockCypherProvider('bitcoin', 'btc');
    $transactions = $provider->fetchTransactions(['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa']);

    expect($transactions)->toHaveCount(0);
});

test('it includes the api token when configured', function () {
    Http::fake([
        'https://api.blockcypher.com/v1/btc/main/addrs/1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa/full*' => function ($request) {
            expect($request->url())->toContain('token=secret');

            return Http::response(['txs' => []]);
        },
    ]);

    $provider = new BlockCypherProvider('bitcoin', 'btc', 'secret');
    $provider->fetchTransactions(['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa']);
});
