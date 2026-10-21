<?php

use App\Models\BlockchainScanState;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\Providers\EvmNativeBisectProvider;
use App\Services\Blockchain\Providers\FallbackBlockchainProvider;
use App\Services\Blockchain\Providers\NodeRealNativeProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const BISECT_ARCHIVE = 'https://archive.test/rpc';
const BISECT_GATE = 'https://gate.test/rpc';
const BISECT_ADDR = '0x00000000000000000000000000000000000000a1';
const ONE_BNB = '0xde0b6b3a7640000';

// $state['steps'][address][block] = balance from that block on (ascending).
function bisectFake(array &$state): void
{
    Http::fake(function (Request $request) use (&$state) {
        $data = $request->data();
        $params = $data['params'] ?? [];
        $state['calls'][] = ['url' => $request->url(), 'method' => $data['method'], 'params' => $params];
        $block = fn (string $hex) => (int) hexdec(substr($hex, 2));

        if (($state['nodereal_down'] ?? false) && str_contains($request->url(), 'nodereal')) {
            return Http::response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32005, 'message' => 'monthly quota']], 429);
        }

        $result = match ($data['method']) {
            'eth_blockNumber' => $state['tip'],
            'eth_getBalance' => bisectBalance($state, strtolower($params[0]), $block($params[1])),
            'eth_getBlockByNumber' => ['transactions' => $state['blocks'][$block($params[0])] ?? []],
            'eth_getTransactionReceipt' => $state['receipts'][$params[0]] ?? null,
            default => null,
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    });
}

function bisectBalance(array $state, string $address, int $block): string
{
    $balance = '0x0';

    foreach ($state['steps'][$address] ?? [] as $from => $value) {
        if ($from <= $block) {
            $balance = $value;
        }
    }

    return $balance;
}

function bisectCalls(array $state, string $method): array
{
    return array_values(array_filter($state['calls'] ?? [], fn ($c) => $c['method'] === $method));
}

function bisectProvider(): EvmNativeBisectProvider
{
    return new EvmNativeBisectProvider('bnb', BISECT_ARCHIVE, BISECT_GATE, fn (int $us) => null);
}

function bisectTx(array $overrides = []): array
{
    return array_merge([
        'hash' => '0xdep',
        'from' => '0x'.str_repeat('7', 40),
        'to' => BISECT_ADDR,
        'value' => ONE_BNB,
    ], $overrides);
}

beforeEach(function () {
    (new ReflectionProperty(EvmNativeBisectProvider::class, 'lastCallAt'))->setValue(null, 0.0);
    PlatformSettings::instance();
});

test('a balance change is bisected to its block and the incoming transfer is returned', function () {
    $treasury = TreasuryWallet::factory()->create(['network' => 'bnb', 'derivation_index' => 0, 'address' => '0x'.str_repeat('a', 40)]);
    GasTopup::create([
        'network' => 'usdt_bep20', 'kind' => 'topup', 'recipient_address' => BISECT_ADDR, 'recipient_index' => 1,
        'treasury_wallet_id' => $treasury->id, 'amount' => '0.0002', 'tx_hash' => '0xtopup', 'status' => 'confirmed', 'is_open' => '1',
    ]);
    BlockchainScanState::query()->create(['network' => 'bnb', 'last_scanned_block' => 900]);

    $state = [
        'tip' => '0x3eb', // 1003 → scans to 1000
        'steps' => [BISECT_ADDR => [990 => ONE_BNB]],
        'blocks' => [990 => [
            bisectTx(),
            bisectTx(['hash' => '0xother', 'to' => '0x'.str_repeat('9', 40)]),
            bisectTx(['hash' => '0xfailed']),
            bisectTx(['hash' => '0xtreasury', 'from' => $treasury->address]),
            bisectTx(['hash' => '0xtopup']),
            bisectTx(['hash' => '0xzero', 'value' => '0x0']),
        ]],
        'receipts' => ['0xdep' => ['status' => '0x1', 'blockNumber' => '0x3de'], '0xfailed' => ['status' => '0x0']],
    ];
    bisectFake($state);

    $transactions = bisectProvider()->fetchTransactions([BISECT_ADDR]);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->txHash)->toBe('0xdep')
        ->and($transactions[0]->toAddress)->toBe(BISECT_ADDR)
        ->and($transactions[0]->amount)->toBe('1.00000000')
        ->and($transactions[0]->confirmations)->toBe(11);

    $blocks = bisectCalls($state, 'eth_getBlockByNumber');
    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['params'][0])->toBe('0x3de')
        ->and($blocks[0]['url'])->toBe(BISECT_ARCHIVE);

    // The latest-state gate goes to the gate RPC; history only to the archive.
    $balances = bisectCalls($state, 'eth_getBalance');
    expect($balances[0]['url'])->toBe(BISECT_GATE)
        ->and(collect($balances)->slice(1)->every(fn ($c) => $c['url'] === BISECT_ARCHIVE))->toBeTrue()
        ->and(count($balances))->toBeLessThan(12);

    expect(BlockchainScanState::where('network', 'bnb')->value('last_scanned_block'))->toBe(1000);
});

test('an address whose balance matches the last scan makes no archive calls', function () {
    BlockchainScanState::query()->create(['network' => 'bnb', 'last_scanned_block' => 900]);
    Cache::put('native-bisect:bnb:balance:'.BISECT_ADDR, '1000000000000000000');
    $state = ['tip' => '0x3eb', 'steps' => [BISECT_ADDR => [10 => ONE_BNB]]];
    bisectFake($state);

    expect(bisectProvider()->fetchTransactions([BISECT_ADDR]))->toBe([]);
    expect(collect($state['calls'])->where('url', BISECT_ARCHIVE))->toBeEmpty();
});

test('pending deposits keep gaining confirmations from their receipt', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bnb', 'address' => BISECT_ADDR, 'derivation_index' => 3]);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id, 'customer_id' => $customer->id, 'user_id' => $owner->id, 'network' => 'bnb',
        'tx_hash' => '0xpending', 'gross_amount' => '0.50000000', 'status' => 'pending', 'confirmation_count' => 4,
    ]);
    BlockchainScanState::query()->create(['network' => 'bnb', 'last_scanned_block' => 1000]);
    Cache::put('native-bisect:bnb:balance:'.BISECT_ADDR, '0');
    $state = ['tip' => '0x3f2', 'receipts' => ['0xpending' => ['status' => '0x1', 'blockNumber' => '0x3e0']]];
    bisectFake($state);

    $transactions = bisectProvider()->fetchTransactions([BISECT_ADDR]);

    // tip 1010 - 3 = 1007; mined at 992 → 16 confirmations.
    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->txHash)->toBe('0xpending')
        ->and($transactions[0]->amount)->toBe('0.50000000')
        ->and($transactions[0]->confirmations)->toBe(16);
});

test('a gap beyond the maximum range is skipped and logged', function () {
    Log::spy();
    BlockchainScanState::query()->create(['network' => 'bnb', 'last_scanned_block' => 100]);
    $state = ['tip' => '0x186a3', 'steps' => [BISECT_ADDR => [99_000 => ONE_BNB]], 'receipts' => []];
    bisectFake($state);

    bisectProvider()->fetchTransactions([BISECT_ADDR]);

    // current = 100003 - 3 = 100000; from = 100000 - 4000.
    $archive = collect(bisectCalls($state, 'eth_getBalance'))->where('url', BISECT_ARCHIVE)->values();
    expect($archive[0]['params'][1])->toBe('0x'.dechex(96_000));
    Log::shouldHaveReceived('warning')->once();
});

test('a nodereal quota failure falls back to bisection and still records the deposit', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bnb', 'address' => BISECT_ADDR, 'derivation_index' => 3]);
    config(['networks.networks.bnb.scan_interval' => 0]);
    BlockchainScanState::query()->create(['network' => 'bnb', 'last_scanned_block' => 900]);

    $state = [
        'nodereal_down' => true,
        'tip' => '0x3eb',
        'steps' => [BISECT_ADDR => [990 => ONE_BNB]],
        'blocks' => [990 => [bisectTx()]],
        'receipts' => ['0xdep' => ['status' => '0x1', 'blockNumber' => '0x3de']],
    ];
    bisectFake($state);

    $provider = new FallbackBlockchainProvider(
        new NodeRealNativeProvider('bnb', 'https://nodereal.test/rpc', fn (int $us) => null),
        bisectProvider(),
    );
    (new DepositScanner([$provider]))->scan();

    $deposit = Deposit::withoutGlobalScope('owner')->where('tx_hash', '0xdep')->sole();
    expect($deposit->network)->toBe('bnb')
        ->and($deposit->status)->toBe('pending')
        ->and($deposit->confirmation_count)->toBe(11);
});
