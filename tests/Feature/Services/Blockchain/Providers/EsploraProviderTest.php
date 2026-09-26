<?php

use App\Services\Blockchain\Providers\EsploraProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

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

test('it retries a transient 503 and returns transactions', function () {
    Sleep::fake();

    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        if (str_contains($request->url(), 'blocks/tip/height')) {
            return Http::response('967179');
        }

        $calls++;

        if ($calls === 1) {
            return Http::response('Service Unavailable', 503);
        }

        return Http::response([[
            'txid' => 'confirmed-1',
            'vout' => [['scriptpubkey_address' => 'bc1qtest', 'value' => 25288]],
            'status' => ['confirmed' => true, 'block_height' => 967127],
        ]]);
    });

    $transactions = (new EsploraProvider('bitcoin'))->fetchTransactions(['bc1qtest']);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->txHash)->toBe('confirmed-1')
        ->and($transactions[0]->confirmations)->toBe(53)
        ->and($calls)->toBe(2);
});

test('it throws the existing error after persistent failures', function () {
    Sleep::fake();

    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        if (str_contains($request->url(), 'blocks/tip/height')) {
            return Http::response('967179');
        }

        $calls++;

        return Http::response('Service Unavailable', 503);
    });

    expect(fn () => (new EsploraProvider('bitcoin'))->fetchTransactions(['bc1qtest']))
        ->toThrow(InvalidArgumentException::class, 'Esplora API returned an error');
    expect($calls)->toBe(3);
});

test('a persistently failing address is skipped and the rest are returned', function () {
    Sleep::fake();
    Log::spy();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'tip/height')) {
            return Http::response('967179');
        }

        if (str_contains($request->url(), '/address/bad/txs')) {
            return Http::response('Service Unavailable', 503);
        }

        preg_match('#/address/(.+)/txs#', $request->url(), $match);

        return Http::response([[
            'txid' => 'tx-'.$match[1],
            'vout' => [['scriptpubkey_address' => $match[1], 'value' => 1000]],
            'status' => ['confirmed' => true, 'block_height' => 967127],
        ]]);
    });

    $transactions = (new EsploraProvider('bitcoin'))->fetchTransactions(['a1', 'bad', 'a3', 'a4']);

    expect($transactions)->toHaveCount(3)
        ->and(collect($transactions)->pluck('txHash')->all())->toBe(['tx-a1', 'tx-a3', 'tx-a4']);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'Esplora address fetch skipped.'
            && $context['network'] === 'bitcoin'
            && $context['skipped'] === 1
            && str_contains($context['first_error'], 'Esplora API returned an error'));
});

test('a dead host aborts the run after the first three failures', function () {
    Sleep::fake();

    $requested = [];
    Http::fake(function (Request $request) use (&$requested) {
        if (str_contains($request->url(), 'tip/height')) {
            return Http::response('967179');
        }

        preg_match('#/address/(.+)/txs#', $request->url(), $match);
        $requested[] = $match[1];

        return Http::response('Service Unavailable', 503);
    });

    expect(fn () => (new EsploraProvider('bitcoin'))->fetchTransactions(['a1', 'a2', 'a3', 'a4']))
        ->toThrow(InvalidArgumentException::class, 'Esplora API returned an error');

    expect(array_values(array_unique($requested)))->toBe(['a1', 'a2', 'a3']);
});

test('a tip failure aborts the run before any address fetch', function () {
    Sleep::fake();

    Http::fake(fn (Request $request) => str_contains($request->url(), 'tip/height')
        ? Http::response('Service Unavailable', 503)
        : Http::response([]));

    expect(fn () => (new EsploraProvider('bitcoin'))->fetchTransactions(['a1']))
        ->toThrow(InvalidArgumentException::class, 'Esplora API returned an error');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/address/'));
});

test('a connection error on one address is skipped', function () {
    Sleep::fake();
    Log::spy();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'tip/height')) {
            return Http::response('967179');
        }

        if (str_contains($request->url(), '/address/flaky/txs')) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        return Http::response([[
            'txid' => 'tx-ok',
            'vout' => [['scriptpubkey_address' => 'ok', 'value' => 1000]],
            'status' => ['confirmed' => true, 'block_height' => 967127],
        ]]);
    });

    $transactions = (new EsploraProvider('bitcoin'))->fetchTransactions(['flaky', 'ok']);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->txHash)->toBe('tx-ok');

    Log::shouldHaveReceived('warning')->once();
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
