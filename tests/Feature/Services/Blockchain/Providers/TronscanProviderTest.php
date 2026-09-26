<?php

use App\Services\Blockchain\Providers\TronscanProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const TRONSCAN_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
const TRONSCAN_ADDRESS = 'TEbVBcJFHgz9HHGbgF4fCRJ6Z2WscXhhLp';

function tronscanItem(array $overrides = []): array
{
    return array_merge([
        'amount' => '13500000',
        'status' => 0,
        'block_timestamp' => 1788099810000,
        'block' => 85809427,
        'from' => 'TNXoiAJ3dct8Fjg4M9fkLFh9S2v9TXc32G',
        'to' => TRONSCAN_ADDRESS,
        'hash' => '590c700e512d31fb12a7ac3a3fea75b897aa6fad02477ef180f6f389e5cd0720',
        'confirmed' => 1,
        'revert' => 0,
        'contract_ret' => 'SUCCESS',
        'decimals' => 6,
        'id' => TRONSCAN_CONTRACT,
        'direction' => 2,
    ], $overrides);
}

function tronscanProvider(array $items = [], ?string $apiKey = null): TronscanProvider
{
    Http::fake(['*' => Http::response([
        'tokenInfo' => ['tokenId' => TRONSCAN_CONTRACT, 'tokenAbbr' => 'USDT', 'tokenDecimal' => 6, 'tokenType' => 'trc20'],
        'page_size' => count($items),
        'code' => 200,
        'data' => $items,
    ])]);

    return new TronscanProvider('usdt_trc20', TRONSCAN_CONTRACT, 'https://apilist.tronscanapi.com', $apiKey);
}

test('tronscan maps incoming trc20 transfers to blockchain transactions', function () {
    $provider = tronscanProvider([tronscanItem()]);

    $transactions = $provider->fetchTransactions([TRONSCAN_ADDRESS]);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->network)->toBe('usdt_trc20')
        ->and($transactions[0]->txHash)->toBe('590c700e512d31fb12a7ac3a3fea75b897aa6fad02477ef180f6f389e5cd0720')
        ->and($transactions[0]->toAddress)->toBe(TRONSCAN_ADDRESS)
        ->and($transactions[0]->amount)->toBe('13.500000')
        ->and($transactions[0]->confirmations)->toBe(20)
        ->and($transactions[0]->tokenContract)->toBe(TRONSCAN_CONTRACT);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/transfer/trc20')
        && str_contains($request->url(), 'address='.TRONSCAN_ADDRESS)
        && str_contains($request->url(), 'trc20Id='.TRONSCAN_CONTRACT)
        && str_contains($request->url(), 'direction=2')
        && str_contains($request->url(), 'reverse=true')
        && str_contains($request->url(), 'limit=50')
        && str_contains($request->url(), 'start=0'));
});

test('tronscan skips transfers that are not successful incoming usdt', function () {
    $provider = tronscanProvider([
        tronscanItem(['hash' => 'ok-1']),
        tronscanItem(['hash' => 'other-to', 'to' => 'TNotTheWatchedAddress0000000000']),
        tronscanItem(['hash' => 'failed', 'contract_ret' => 'REVERT']),
        tronscanItem(['hash' => 'reverted', 'revert' => 1]),
        tronscanItem(['hash' => 'unconfirmed', 'confirmed' => 0]),
    ]);

    $transactions = $provider->fetchTransactions([TRONSCAN_ADDRESS]);

    expect($transactions)->toHaveCount(2)
        ->and($transactions[0]->txHash)->toBe('ok-1')
        ->and($transactions[0]->confirmations)->toBe(20)
        ->and($transactions[1]->txHash)->toBe('unconfirmed')
        ->and($transactions[1]->confirmations)->toBe(0);
});

test('tronscan sends the api key header only when configured', function () {
    tronscanProvider([], 'secret-key')->fetchTransactions([TRONSCAN_ADDRESS]);

    Http::assertSent(fn (Request $request) => $request->header('TRON-PRO-API-KEY') === ['secret-key']);

    tronscanProvider()->fetchTransactions([TRONSCAN_ADDRESS]);

    Http::assertSent(fn (Request $request) => $request->header('TRON-PRO-API-KEY') === []);
});

test('tronscan raises provider http failures', function () {
    Http::fake(['*' => Http::response('Server error', 500)]);

    $provider = new TronscanProvider('usdt_trc20', TRONSCAN_CONTRACT, 'https://apilist.tronscanapi.com');

    expect(fn () => $provider->fetchTransactions([TRONSCAN_ADDRESS]))
        ->toThrow(InvalidArgumentException::class, 'Tronscan API returned an error');
});

test('tronscan raises on a non-200 api code', function () {
    Http::fake(['*' => Http::response(['code' => 500, 'data' => []])]);

    $provider = new TronscanProvider('usdt_trc20', TRONSCAN_CONTRACT, 'https://apilist.tronscanapi.com');

    expect(fn () => $provider->fetchTransactions([TRONSCAN_ADDRESS]))
        ->toThrow(InvalidArgumentException::class);
});

test('tronscan paces id-keyed address lists once per gap', function () {
    Sleep::fake();

    // DepositScanner passes id-keyed arrays: three addresses must still pace
    // exactly N-1 sleeps.
    tronscanProvider([])->fetchTransactions([
        17 => TRONSCAN_ADDRESS,
        42 => 'TSecondAddress0000000000000000000',
        77 => 'TThirdAddress000000000000000000',
    ]);

    Sleep::assertSleptTimes(2);
    Http::assertSentCount(3);
});

test('tronscan returns early for empty address lists and paces multiple requests', function () {
    Sleep::fake();

    $provider = tronscanProvider([]);
    expect($provider->fetchTransactions([]))->toBe([]);
    Http::assertNothingSent();

    $provider->fetchTransactions([TRONSCAN_ADDRESS, 'TSecondAddress0000000000000000000']);

    Sleep::assertSequence([Sleep::for(250)->milliseconds()]);
});
