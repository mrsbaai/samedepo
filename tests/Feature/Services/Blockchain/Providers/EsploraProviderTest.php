<?php

use App\Services\Blockchain\Providers\EsploraProvider;
use Illuminate\Support\Facades\Http;

test('it parses incoming outputs and derives confirmations from the chain tip', function () {
    Http::fake([
        'https://mempool.space/api/blocks/tip/height' => Http::response('967179'),
        'https://mempool.space/api/address/bc1qtest/txs' => Http::response([
            [
                'txid' => 'confirmed-1',
                'vout' => [
                    ['scriptpubkey_address' => 'bc1qtest', 'value' => 25288],
                    ['scriptpubkey_address' => 'bc1qother', 'value' => 999],
                ],
                'status' => ['confirmed' => true, 'block_height' => 967127],
            ],
            [
                'txid' => 'unconfirmed-1',
                'vout' => [
                    ['scriptpubkey_address' => 'bc1qtest', 'value' => 50000000],
                ],
                'status' => ['confirmed' => false],
            ],
            [
                'txid' => 'outgoing-only',
                'vout' => [
                    ['scriptpubkey_address' => 'bc1qother', 'value' => 100],
                ],
                'status' => ['confirmed' => true, 'block_height' => 967000],
            ],
            [
                'txid' => 'multi-output',
                'vout' => [
                    ['scriptpubkey_address' => 'bc1qtest', 'value' => 10000000],
                    ['scriptpubkey_address' => 'BC1QTEST', 'value' => 20000000],
                ],
                'status' => ['confirmed' => true, 'block_height' => 967179],
            ],
        ]),
    ]);

    $transactions = (new EsploraProvider('bitcoin'))->fetchTransactions(['bc1qtest']);

    expect($transactions)->toHaveCount(3)
        ->and($transactions[0]->txHash)->toBe('confirmed-1')
        ->and($transactions[0]->amount)->toBe('0.00025288')
        ->and($transactions[0]->confirmations)->toBe(53)
        ->and($transactions[1]->txHash)->toBe('unconfirmed-1')
        ->and($transactions[1]->amount)->toBe('0.50000000')
        ->and($transactions[1]->confirmations)->toBe(0)
        ->and($transactions[2]->txHash)->toBe('multi-output')
        ->and($transactions[2]->amount)->toBe('0.30000000')
        ->and($transactions[2]->confirmations)->toBe(1);

    Http::assertSentCount(2);
});

test('it uses the configured base url and makes no calls for an empty address list', function () {
    $provider = new EsploraProvider('bitcoin', 'https://blockstream.info/api/');

    Http::fake();

    expect($provider->fetchTransactions([]))->toBe([]);
    Http::assertNothingSent();

    Http::fake([
        'https://blockstream.info/api/blocks/tip/height' => Http::response('100'),
        'https://blockstream.info/api/address/addr/txs' => Http::response([]),
    ]);

    expect($provider->fetchTransactions(['addr']))->toBe([]);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://blockstream.info/api/address/addr/txs'));
});

test('it skips malformed transactions', function () {
    Http::fake([
        'https://mempool.space/api/blocks/tip/height' => Http::response('10'),
        'https://mempool.space/api/address/addr/txs' => Http::response([
            null,
            'bad',
            ['txid' => '', 'vout' => [['scriptpubkey_address' => 'addr', 'value' => 5]]],
            ['txid' => 'zero', 'vout' => 'invalid'],
            ['txid' => 'novout'],
        ]),
    ]);

    expect((new EsploraProvider('bitcoin'))->fetchTransactions(['addr']))->toBe([]);
});
