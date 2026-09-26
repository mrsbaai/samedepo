<?php

use App\Services\Blockchain\Providers\BlockCypherProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

const BLOCKCYPHER_BASE = 'https://api.blockcypher.com/v1/ltc/main';
const BLOCKCYPHER_ADDRESS = 'LfLqRoB2cEMQ9aXfismTXvReT9bvJNTTTN';

function blockcypherTx(string $address, array $overrides = []): array
{
    return array_merge([
        'block_height' => 3184760,
        'hash' => '011748a71f890777c9c379c95b7580aa470f15481c857b3b13f2e79b9b1c1739',
        'confirmations' => 1,
        'inputs' => [
            ['output_value' => 24713256213, 'addresses' => ['LfPt4mh2qw5oiCWBXj86M9bgtGpcpGkcUv']],
        ],
        'outputs' => [
            ['value' => 124369990, 'addresses' => [$address]],
            ['value' => 24588786224, 'addresses' => ['LfPt4mh2qw5oiCWBXj86M9bgtGpcpGkcUv']],
        ],
    ], $overrides);
}

function blockcypherResponse(string $address, array $txs): array
{
    return [
        'address' => $address,
        'balance' => 292017967,
        'n_tx' => count($txs),
        'hasMore' => false,
        'txs' => $txs,
    ];
}

// Fakes keyed on the requested address so outputs always pay it. The $state
// array is read per request, so a test can change failures mid-run.
function blockcypherFake(array &$state): void
{
    Http::fake(function (Request $request) use (&$state) {
        preg_match('#/addrs/([^/]+)/full#', $request->url(), $match);
        $address = $match[1] ?? '';

        $failing = $state['failing'] ?? [];

        if (isset($failing[$address])) {
            return Http::response($failing[$address][0], $failing[$address][1]);
        }

        return Http::response(blockcypherResponse($address, [
            blockcypherTx($address, ['hash' => 'tx-'.$address]),
        ]));
    });
}

function blockcypherProvider(?string $token = null): BlockCypherProvider
{
    return new BlockCypherProvider('litecoin', BLOCKCYPHER_BASE, $token);
}

test('blockcypher sums only the outputs paying the watched address', function () {
    Sleep::fake();

    Http::fake(['*' => Http::response(blockcypherResponse(BLOCKCYPHER_ADDRESS, [
        // Two outputs to the watched address sum to 124369990 + 10 sats.
        blockcypherTx(BLOCKCYPHER_ADDRESS, [
            'hash' => 'tx-sum',
            'confirmations' => 6,
            'outputs' => [
                ['value' => 124369990, 'addresses' => [BLOCKCYPHER_ADDRESS]],
                ['value' => 24588786224, 'addresses' => ['other-address']],
                ['value' => 10, 'addresses' => [BLOCKCYPHER_ADDRESS, 'other-address']],
            ],
        ]),
        // No output to the watched address: a spend, skipped.
        blockcypherTx(BLOCKCYPHER_ADDRESS, [
            'hash' => 'tx-spend',
            'outputs' => [['value' => 100, 'addresses' => ['other-address']]],
        ]),
    ]))]);

    $transactions = blockcypherProvider()->fetchTransactions([BLOCKCYPHER_ADDRESS]);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->network)->toBe('litecoin')
        ->and($transactions[0]->txHash)->toBe('tx-sum')
        ->and($transactions[0]->toAddress)->toBe(BLOCKCYPHER_ADDRESS)
        ->and($transactions[0]->amount)->toBe('1.24370000')
        ->and($transactions[0]->confirmations)->toBe(6)
        ->and($transactions[0]->tokenContract)->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/addrs/'.BLOCKCYPHER_ADDRESS.'/full')
        && str_contains($request->url(), 'limit=50'));
});

test('blockcypher stops on 429 and returns the partial scan with one warning', function () {
    Sleep::fake();
    Log::spy();

    $addresses = ['addr-a', 'addr-b', 'addr-c', 'addr-d'];
    $state = ['failing' => ['addr-c' => ['Too Many Requests', 429]]];
    blockcypherFake($state);

    $transactions = blockcypherProvider()->fetchTransactions($addresses);

    expect($transactions)->toHaveCount(2);
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'addr-d'));

    Log::shouldHaveReceived('warning')->once()->with(
        'BlockCypher rate limit; partial scan.',
        Mockery::on(fn (array $context): bool => $context['network'] === 'litecoin'
            && $context['scanned'] === 2
            && $context['total'] === 4),
    );
});

test('blockcypher throws when the first address hits 429', function () {
    Sleep::fake();
    $state = ['failing' => ['addr-a' => ['Too Many Requests', 429]]];
    blockcypherFake($state);

    expect(fn () => blockcypherProvider()->fetchTransactions(['addr-a', 'addr-b']))
        ->toThrow(InvalidArgumentException::class);
});

test('blockcypher throws on a first address failure', function () {
    Sleep::fake();
    $state = ['failing' => ['bad-addr' => ['{"error": "invalid address"}', 400]]];
    blockcypherFake($state);

    expect(fn () => blockcypherProvider()->fetchTransactions(['bad-addr', 'addr-b']))
        ->toThrow(InvalidArgumentException::class);
});

test('blockcypher skips later address failures with one warning', function () {
    Sleep::fake();
    Log::spy();

    $state = ['failing' => ['addr-b' => ['{"error": "invalid address"}', 400]]];
    blockcypherFake($state);

    $transactions = blockcypherProvider()->fetchTransactions(['addr-a', 'addr-b', 'addr-c']);

    expect($transactions)->toHaveCount(2);
    Log::shouldHaveReceived('warning')->once()->with(
        'BlockCypher address fetch skipped.',
        Mockery::on(fn (array $context): bool => $context['network'] === 'litecoin'
            && $context['skipped'] === 1
            && str_contains($context['first_error'], 'invalid address')),
    );
});

test('blockcypher rotates the start address across runs after a partial scan', function () {
    Sleep::fake();

    $addresses = ['addr-a', 'addr-b', 'addr-c', 'addr-d'];

    // Run one stops at addr-c: offset advances by the two scanned addresses.
    $state = ['failing' => ['addr-c' => ['Too Many Requests', 429]]];
    blockcypherFake($state);
    blockcypherProvider()->fetchTransactions($addresses);
    expect(Cache::get('blockcypher:litecoin:offset'))->toBe(2);

    // Run two must resume at addr-c and wrap around through the rest.
    $state['failing'] = [];
    blockcypherProvider()->fetchTransactions($addresses);

    $urls = Http::recorded()->slice(3)->map(fn ($pair) => $pair[0]->url())->values();
    expect($urls)->toHaveCount(4)
        ->and($urls[0])->toContain('/addrs/addr-c/full')
        ->and($urls[1])->toContain('/addrs/addr-d/full')
        ->and($urls[2])->toContain('/addrs/addr-a/full')
        ->and($urls[3])->toContain('/addrs/addr-b/full');
});

test('blockcypher appends the token only when configured', function () {
    Sleep::fake();
    $state = [];
    blockcypherFake($state);

    blockcypherProvider('tok123')->fetchTransactions(['addr-a']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'token=tok123'));

    blockcypherProvider()->fetchTransactions(['addr-a']);
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'token='));
});

test('blockcypher treats the first id-keyed address as the first address', function () {
    Sleep::fake();

    // DepositScanner passes id-keyed arrays: the first element must still
    // count as "the first address" for the throw-on-first rules.
    $state = ['failing' => ['addr-a' => ['Too Many Requests', 429]]];
    blockcypherFake($state);

    expect(fn () => blockcypherProvider()->fetchTransactions([17 => 'addr-a', 42 => 'addr-b']))
        ->toThrow(InvalidArgumentException::class);

    $state['failing'] = ['addr-a' => ['{"error": "upstream"}', 500]];
    expect(fn () => blockcypherProvider()->fetchTransactions([17 => 'addr-a', 42 => 'addr-b']))
        ->toThrow(InvalidArgumentException::class);

    // Rotation also works with id keys: a partial run scanning two of four
    // advances the offset past them, so the next run starts at addr-c.
    $state['failing'] = ['addr-c' => ['Too Many Requests', 429]];
    blockcypherProvider()->fetchTransactions([7 => 'addr-a', 9 => 'addr-b', 11 => 'addr-c', 13 => 'addr-d']);
    expect(Cache::get('blockcypher:litecoin:offset'))->toBe(2);

    $state['failing'] = [];
    $before = count(Http::recorded());
    blockcypherProvider()->fetchTransactions([7 => 'addr-a', 9 => 'addr-b', 11 => 'addr-c', 13 => 'addr-d']);

    expect(Http::recorded()->slice($before)->map(fn ($pair) => $pair[0]->url())->first())
        ->toContain('/addrs/addr-c/full');
});

test('blockcypher returns early for empty address lists', function () {
    expect(blockcypherProvider()->fetchTransactions([]))->toBe([]);
    Http::assertNothingSent();
});
