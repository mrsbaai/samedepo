<?php

use App\Models\BlockchainScanState;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Services\Blockchain\DepositCreditor;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\Providers\NodeRealNativeProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const NODEREAL_RPC = 'https://nodereal.test/rpc';
const NODEREAL_ADDR = '0x0000000000000000000000000000000000000001';

// Fake the single JSON-RPC endpoint. The $state array is read on every
// request, so a test can mutate it mid-run (new tip, an error, ...).
// $state['nr'] is a closure receiving params[0] and returning `result`.
function noderealFake(array &$state): void
{
    Http::fake(function (Request $request) use (&$state) {
        if (($state['error'] ?? null) !== null) {
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => $state['error']]]);
        }

        $data = $request->data();
        $params = $data['params'] ?? [];

        $result = match ($data['method'] ?? '') {
            'eth_blockNumber' => $state['tip'] ?? '0x400', // 1024
            'eth_getBalance' => $state['balances'][$params[0]] ?? '0x0',
            'eth_getTransactionCount' => $state['nonces'][$params[0]] ?? '0x0',
            'nr_getTransactionByAddress' => ($state['nr'] ?? fn (array $p) => ['pageKey' => '', 'transfers' => []])($params[0]),
            default => null,
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    });
}

function noderealTransfer(array $overrides = []): array
{
    return array_merge([
        'id' => 361827,
        'category' => 'external',
        'blockNum' => '0x3f0',
        'from' => '0x'.str_pad('77', 40, '7', STR_PAD_LEFT),
        'to' => NODEREAL_ADDR,
        'value' => '0xde0b6b3a7640000', // 1 BNB
        'asset' => 'BNB',
        'hash' => '0xnr-tx-1',
        'receiptsStatus' => 1,
    ], $overrides);
}

function noderealProvider(?callable $sleeper = null): NodeRealNativeProvider
{
    return new NodeRealNativeProvider(
        network: 'bnb',
        rpcUrl: NODEREAL_RPC,
        sleeper: $sleeper ?? fn (int $us) => null,
    );
}

function noderealRequests(string $method): array
{
    return Http::recorded()
        ->filter(fn ($pair) => ($pair[0]->data()['method'] ?? null) === $method)
        ->values()
        ->map(fn ($pair) => $pair[0]->data())
        ->all();
}

function noderealOwner(): array
{
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    PlatformSettings::instance();

    return [$owner, $customer];
}

beforeEach(function () {
    // The throttle timestamps are static — reset them so a previous test's
    // clock does not change what this test measures.
    (new ReflectionProperty(NodeRealNativeProvider::class, 'lastGateCallAt'))->setValue(null, 0.0);
    (new ReflectionProperty(NodeRealNativeProvider::class, 'lastNrCallAt'))->setValue(null, 0.0);
});

test('nodereal returns early without an rpc url or addresses', function () {
    expect((new NodeRealNativeProvider('bnb', null))->fetchTransactions(['0x1']))->toBe([])
        ->and((new NodeRealNativeProvider('bnb', ''))->fetchTransactions(['0x1']))->toBe([])
        ->and(noderealProvider()->fetchTransactions([]))->toBe([]);

    Http::assertNothingSent();
});

test('nodereal gates unchanged addresses at the current block and only queries candidates', function () {
    $addr2 = '0x'.str_pad('2', 40, '0', STR_PAD_LEFT);
    Cache::put('nodereal:bnb:snapshot:'.strtolower(NODEREAL_ADDR), ['balance' => '0x10', 'nonce' => '0x1']);

    $state = [
        'balances' => [NODEREAL_ADDR => '0x10', $addr2 => '0x20'],
        'nonces' => [NODEREAL_ADDR => '0x1', $addr2 => '0x0'],
    ];
    noderealFake($state);

    noderealProvider()->fetchTransactions([NODEREAL_ADDR, $addr2]);

    // Every gate call reads state at the current block hex, never 'latest'.
    $gates = array_merge(noderealRequests('eth_getBalance'), noderealRequests('eth_getTransactionCount'));
    expect($gates)->toHaveCount(4);

    foreach ($gates as $data) {
        expect($data['params'][1])->toBe('0x400');
    }

    // The unchanged address produced no nr_* query; the new one did.
    $nr = noderealRequests('nr_getTransactionByAddress');
    expect($nr)->toHaveCount(1)
        ->and($nr[0]['params'][0]['address'])->toBe($addr2)
        ->and($nr[0]['params'][0]['category'])->toBe(['external'])
        ->and($nr[0]['params'][0]['addressType'])->toBe('to')
        ->and($nr[0]['params'][0]['maxCount'])->toBe('0x3E8')
        ->and($nr[0]['params'][0]['fromBlock'])->toBe('0x19') // 1024 - 999
        ->and($nr[0]['params'][0]['toBlock'])->toBe('0x400');
});

test('nodereal re-queries an address while it has an unconfirmed pending deposit', function () {
    [$owner, $customer] = noderealOwner();
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bnb',
        'address' => NODEREAL_ADDR,
        'derivation_index' => 3,
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bnb',
        'tx_hash' => '0xnr-tx-1',
        'gross_amount' => '1.00000000',
        'status' => 'pending',
        'confirmation_count' => 5,
    ]);

    // Snapshot matches the gate response — only the pending deposit can
    // trigger the query.
    Cache::put('nodereal:bnb:snapshot:'.strtolower(NODEREAL_ADDR), ['balance' => '0x10', 'nonce' => '0x1']);
    $state = ['balances' => [NODEREAL_ADDR => '0x10'], 'nonces' => [NODEREAL_ADDR => '0x1']];
    noderealFake($state);

    noderealProvider()->fetchTransactions([NODEREAL_ADDR]);
    expect(noderealRequests('nr_getTransactionByAddress'))->toHaveCount(1);
});

test('nodereal skips an unchanged address whose pending deposit is fully confirmed', function () {
    [$owner, $customer] = noderealOwner();
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bnb',
        'address' => NODEREAL_ADDR,
        'derivation_index' => 3,
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bnb',
        'tx_hash' => '0xnr-tx-1',
        'gross_amount' => '1.00000000',
        'status' => 'pending',
        'confirmation_count' => 15,
    ]);

    Cache::put('nodereal:bnb:snapshot:'.strtolower(NODEREAL_ADDR), ['balance' => '0x10', 'nonce' => '0x1']);
    $state = ['balances' => [NODEREAL_ADDR => '0x10'], 'nonces' => [NODEREAL_ADDR => '0x1']];
    noderealFake($state);

    noderealProvider()->fetchTransactions([NODEREAL_ADDR]);
    expect(noderealRequests('nr_getTransactionByAddress'))->toHaveCount(0);
});

test('nodereal scans contiguous 1000 block chunks and paginates on page key', function () {
    // last_scanned_block = 10 → from = max(0, 11 - 14) = 0 → chunks
    // [0, 999] and [1000, 1024].
    BlockchainScanState::query()->create(['network' => 'bnb', 'last_scanned_block' => 10]);

    $pages = 0;
    $state = [
        'nr' => function (array $params) use (&$pages) {
            if ($params['fromBlock'] === '0x0') {
                $pages++;

                return $pages === 1
                    ? ['pageKey' => 'k2', 'transfers' => [noderealTransfer()]]
                    : ['pageKey' => '', 'transfers' => [noderealTransfer(['hash' => '0xnr-tx-2', 'blockNum' => '0x3e8'])]];
            }

            return ['pageKey' => '', 'transfers' => []];
        },
    ];
    noderealFake($state);

    $transactions = noderealProvider()->fetchTransactions([NODEREAL_ADDR]);

    $nr = noderealRequests('nr_getTransactionByAddress');
    expect($nr)->toHaveCount(3)
        ->and($nr[0]['params'][0]['fromBlock'])->toBe('0x0')
        ->and($nr[0]['params'][0]['toBlock'])->toBe('0x3e7') // 999
        ->and($nr[0]['params'][0])->not->toHaveKey('pageKey')
        ->and($nr[1]['params'][0]['pageKey'])->toBe('k2')
        ->and($nr[2]['params'][0]['fromBlock'])->toBe('0x3e8') // 1000
        ->and($nr[2]['params'][0]['toBlock'])->toBe('0x400');

    expect($transactions)->toHaveCount(2);
});

test('nodereal maps transfers and skips non-deposits', function () {
    $watched = '0x'.str_pad('ab', 40, '0', STR_PAD_LEFT); // lettered, lowercase
    $treasury = TreasuryWallet::factory()->create([
        'network' => 'usdt_bep20',
        'derivation_index' => 0,
        'address' => '0x'.str_pad('aa', 40, 'a', STR_PAD_LEFT),
    ]);
    GasTopup::create([
        'network' => 'usdt_bep20',
        'kind' => 'topup',
        'recipient_address' => $watched,
        'recipient_index' => 1,
        'treasury_wallet_id' => $treasury->id,
        'amount' => '0.00020000',
        'tx_hash' => '0xtopup-hash',
        'status' => 'confirmed',
        'is_open' => '1',
    ]);

    $state = [
        'nr' => fn () => ['pageKey' => '', 'transfers' => [
            noderealTransfer(['to' => strtoupper($watched)]), // case-insensitive match
            noderealTransfer(['hash' => '0xother-to', 'to' => '0x'.str_pad('9', 40, '0', STR_PAD_LEFT)]),
            noderealTransfer(['hash' => '0xfailed', 'to' => $watched, 'receiptsStatus' => 0]),
            noderealTransfer(['hash' => '0xzero', 'to' => $watched, 'value' => '0x0']),
            noderealTransfer(['hash' => '0xfrom-treasury', 'to' => $watched, 'from' => $treasury->address]),
            noderealTransfer(['hash' => '0xtopup-hash', 'to' => $watched]),
            noderealTransfer(['hash' => '0xnr-tx-1', 'to' => $watched]), // duplicate hash within the run
            noderealTransfer(['hash' => '0xinternal', 'category' => 'internal', 'to' => $watched]),
        ]],
    ];
    noderealFake($state);

    $transactions = noderealProvider()->fetchTransactions([$watched]);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->network)->toBe('bnb')
        ->and($transactions[0]->txHash)->toBe('0xnr-tx-1')
        ->and($transactions[0]->toAddress)->toBe($watched)
        ->and($transactions[0]->amount)->toBe('1.00000000')
        // 1024 - 1008 + 1 = 17 confirmations.
        ->and($transactions[0]->confirmations)->toBe(17)
        ->and($transactions[0]->tokenContract)->toBeNull();
});

test('nodereal persists state only after the whole scan succeeds', function () {
    $state = [];
    noderealFake($state);
    noderealProvider()->fetchTransactions([NODEREAL_ADDR]);

    expect(BlockchainScanState::where('network', 'bnb')->value('last_scanned_block'))->toBe(1024)
        ->and(Cache::get('nodereal:bnb:snapshot:'.strtolower(NODEREAL_ADDR)))->toBe(['balance' => '0x0', 'nonce' => '0x0']);

    // An nr_* failure must throw and leave both the snapshot cache and the
    // scan state untouched.
    $state['error'] = 'upstream down';

    expect(fn () => noderealProvider()->fetchTransactions(['0x'.str_pad('3', 40, '0', STR_PAD_LEFT)]))
        ->toThrow(RuntimeException::class);

    expect(BlockchainScanState::where('network', 'bnb')->value('last_scanned_block'))->toBe(1024)
        ->and(Cache::has('nodereal:bnb:snapshot:0x0000000000000000000000000000000000000003'))->toBeFalse();
});

test('nodereal paces nr calls one second apart and gate calls 250ms apart', function () {
    $sleeps = [];
    $provider = noderealProvider(function (int $us) use (&$sleeps): void {
        $sleeps[] = $us;
    });

    $state = [];
    noderealFake($state);
    $provider->fetchTransactions([NODEREAL_ADDR, '0x'.str_pad('2', 40, '0', STR_PAD_LEFT)]);

    // Both addresses are candidates: the second nr_* call waits ~1 s; the
    // block-number call leads, then the four gate calls are spaced ~250 ms.
    expect(collect($sleeps)->filter(fn (int $us) => $us > 500_000)->count())->toBe(1)
        ->and(collect($sleeps)->filter(fn (int $us) => $us <= 500_000)->count())->toBeGreaterThanOrEqual(3);
});

test('bnb scan detects a deposit pending then credits at 15 confirmations via nodereal', function () {
    [$owner, $customer] = noderealOwner();
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bnb',
        'address' => NODEREAL_ADDR,
        'derivation_index' => 3,
    ]);
    config(['networks.networks.bnb.scan_interval' => 0]);

    $state = [
        'tip' => '0x64', // 100
        'balances' => [NODEREAL_ADDR => '0x10'],
        'nonces' => [NODEREAL_ADDR => '0x0'],
        'nr' => fn () => ['pageKey' => '', 'transfers' => [
            noderealTransfer(['blockNum' => '0x60']), // block 96 → 5 conf at tip 100
        ]],
    ];
    noderealFake($state);

    (new DepositScanner([noderealProvider()]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit = Deposit::where('tx_hash', '0xnr-tx-1')->first();
    expect($deposit)->not->toBeNull()
        ->and($deposit->network)->toBe('bnb')
        ->and($deposit->status)->toBe('pending')
        ->and($deposit->confirmation_count)->toBe(5);

    // Second run: the gate snapshot is unchanged, but the pending deposit
    // keeps the address a candidate; the advancing tip grows confirmations.
    $state['tip'] = '0x78'; // 120 → 120 - 96 + 1 = 25 conf

    (new DepositScanner([noderealProvider()]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited')
        ->and($deposit->confirmation_count)->toBe(25)
        ->and(BlockchainScanState::where('network', 'bnb')->value('last_scanned_block'))->toBe(120);
});
