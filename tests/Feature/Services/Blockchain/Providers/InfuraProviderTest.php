<?php

use App\Models\BlockchainScanState;
use App\Services\Blockchain\Providers\InfuraProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function infuraAddress(int $number): string
{
    return '0x'.str_pad(dechex($number), 40, '0', STR_PAD_LEFT);
}

function infuraTopic(string $address): string
{
    return '0x'.str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
}

test('it scans ten thousand block chunks up to the tip and resumes after it on the next invocation', function () {
    BlockchainScanState::query()->create([
        'network' => 'usdt_erc20',
        'last_scanned_block' => 4999,
    ]);
    $addresses = array_map(infuraAddress(...), range(1, 101));
    $requests = [];

    Http::fake(function (Request $request) use (&$requests, $addresses) {
        $payload = $request->data();

        if ($payload['method'] === 'eth_blockNumber') {
            return Http::response(['jsonrpc' => '2.0', 'result' => '0x61a7', 'id' => 2]);
        }

        $requests[] = $payload;
        $result = count($requests) === 1 ? [[
            'blockNumber' => '0x1388',
            'transactionHash' => '0xtransaction',
            'data' => '0xf4240',
            'topics' => ['', '', infuraTopic($addresses[0])],
        ]] : [];

        return Http::response(['jsonrpc' => '2.0', 'result' => $result, 'id' => 1]);
    });

    $provider = new InfuraProvider(
        network: 'usdt_erc20',
        contract: '0xdac17f958d2ee523a2206206994597c13d831ec7',
        projectId: 'project',
    );
    $transactions = $provider->fetchTransactions($addresses);

    // Chunks [4989, 14988], [14989, 24988], [24989, 24999] × two recipient
    // batches (100 + 1) = six log requests.
    expect($requests)->toHaveCount(6)
        ->and($requests[0]['params'][0]['fromBlock'])->toBe('0x137d')
        ->and($requests[0]['params'][0]['toBlock'])->toBe('0x3a8c')
        ->and($requests[0]['params'][0]['topics'][2])->toHaveCount(100)
        ->and($requests[1]['params'][0]['topics'][2])->toHaveCount(1)
        ->and($requests[2]['params'][0]['fromBlock'])->toBe('0x3a8d')
        ->and($requests[2]['params'][0]['toBlock'])->toBe('0x619c')
        ->and($requests[4]['params'][0]['fromBlock'])->toBe('0x619d')
        ->and($requests[4]['params'][0]['toBlock'])->toBe('0x61a7')
        ->and($transactions)->toHaveCount(1)
        ->and($transactions[0]->toAddress)->toBe($addresses[0])
        ->and(BlockchainScanState::query()->where('network', 'usdt_erc20')->value('last_scanned_block'))->toBe(24999);

    $provider->fetchTransactions($addresses);

    // Next run overlaps the confirmation window: [24989, 24999].
    expect($requests)->toHaveCount(8)
        ->and($requests[6]['params'][0]['fromBlock'])->toBe('0x619d')
        ->and($requests[6]['params'][0]['toBlock'])->toBe('0x61a7')
        ->and(BlockchainScanState::query()->where('network', 'usdt_erc20')->value('last_scanned_block'))->toBe(24999);
});

test('it defaults a new scan state to the latest ten thousand blocks', function () {
    $logRequest = null;
    Http::fake(function (Request $request) use (&$logRequest) {
        if ($request->data()['method'] === 'eth_blockNumber') {
            return Http::response(['result' => '0x4e20']);
        }

        $logRequest = $request->data();

        return Http::response(['result' => []]);
    });

    (new InfuraProvider('usdt_erc20', '0xcontract', 'project'))->fetchTransactions([infuraAddress(1)]);

    expect($logRequest['params'][0]['fromBlock'])->toBe('0x2711')
        ->and($logRequest['params'][0]['toBlock'])->toBe('0x4e20')
        ->and(BlockchainScanState::query()->value('last_scanned_block'))->toBe(20000);
});

test('it overlaps confirmed blocks so a transaction returns with increased confirmations', function () {
    config(['blockchain.confirmations.usdt_erc20' => 12]);
    BlockchainScanState::query()->create([
        'network' => 'usdt_erc20',
        'last_scanned_block' => 1000,
    ]);
    $address = infuraAddress(1);
    $currentBlocks = [1000, 1001];
    $ranges = [];

    Http::fake(function (Request $request) use (&$currentBlocks, &$ranges, $address) {
        $payload = $request->data();

        if ($payload['method'] === 'eth_blockNumber') {
            return Http::response(['result' => '0x'.dechex(array_shift($currentBlocks))]);
        }

        $ranges[] = $payload['params'][0];

        return Http::response(['result' => [[
            'blockNumber' => '0x3e7',
            'transactionHash' => '0xpending',
            'data' => '0xf4240',
            'topics' => ['', '', infuraTopic($address)],
        ]]]);
    });

    $provider = new InfuraProvider('usdt_erc20', '0xcontract', 'project');
    $first = $provider->fetchTransactions([$address]);
    $second = $provider->fetchTransactions([$address]);

    expect($first)->toHaveCount(1)
        ->and($first[0]->confirmations)->toBe(2)
        ->and($second)->toHaveCount(1)
        ->and($second[0]->confirmations)->toBe(3)
        ->and(hexdec($ranges[0]['fromBlock']))->toBe(990)
        ->and(hexdec($ranges[1]['fromBlock']))->toBe(990)
        ->and(BlockchainScanState::query()->value('last_scanned_block'))->toBe(1001);
});

test('overlapped infura ranges never exceed ten thousand blocks', function () {
    config(['blockchain.confirmations.usdt_erc20' => 12]);
    BlockchainScanState::query()->create([
        'network' => 'usdt_erc20',
        'last_scanned_block' => 20000,
    ]);
    $ranges = [];

    Http::fake(function (Request $request) use (&$ranges) {
        $payload = $request->data();

        if ($payload['method'] === 'eth_blockNumber') {
            return Http::response(['result' => '0x'.dechex(40000)]);
        }

        $ranges[] = $payload['params'][0];

        return Http::response(['result' => []]);
    });

    (new InfuraProvider('usdt_erc20', '0xcontract', 'project'))->fetchTransactions([infuraAddress(1)]);

    // 20001 − 11 → [19990, 29989], [29990, 39989], [39990, 40000]: every chunk
    // stays ≤ 10000 blocks and contiguous up to the tip.
    expect($ranges)->toHaveCount(3)
        ->and(hexdec($ranges[0]['fromBlock']))->toBe(19990)
        ->and(hexdec($ranges[2]['toBlock']))->toBe(40000)
        ->and(BlockchainScanState::query()->value('last_scanned_block'))->toBe(40000);

    foreach ($ranges as $index => $range) {
        expect(hexdec($range['toBlock']) - hexdec($range['fromBlock']) + 1)->toBeLessThanOrEqual(10000);

        if ($index > 0) {
            expect(hexdec($range['fromBlock']))->toBe(hexdec($ranges[$index - 1]['toBlock']) + 1);
        }
    }
});

test('it does not advance scan state when any log request fails', function () {
    BlockchainScanState::query()->create([
        'network' => 'usdt_erc20',
        'last_scanned_block' => 100,
    ]);
    $logRequests = 0;

    Http::fake(function (Request $request) use (&$logRequests) {
        if ($request->data()['method'] === 'eth_blockNumber') {
            return Http::response(['result' => '0x271a']);
        }

        $logRequests++;

        return $logRequests === 2
            ? Http::response(['error' => ['message' => 'request failed']])
            : Http::response(['result' => []]);
    });

    expect(fn () => (new InfuraProvider('usdt_erc20', '0xcontract', 'project'))->fetchTransactions(array_map(infuraAddress(...), range(1, 101))))
        ->toThrow(InvalidArgumentException::class, 'request failed');

    expect(BlockchainScanState::query()->value('last_scanned_block'))->toBe(100);
});
