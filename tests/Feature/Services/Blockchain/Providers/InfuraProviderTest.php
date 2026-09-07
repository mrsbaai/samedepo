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

test('it resumes in ten thousand block chunks and batches recipient topics', function () {
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

    $transactions = (new InfuraProvider(
        network: 'usdt_erc20',
        usdtContract: '0xdac17f958d2ee523a2206206994597c13d831ec7',
        projectId: 'project',
    ))->fetchTransactions($addresses);

    expect($requests)->toHaveCount(4)
        ->and($requests[0]['params'][0]['fromBlock'])->toBe('0x1388')
        ->and($requests[0]['params'][0]['toBlock'])->toBe('0x3a97')
        ->and($requests[2]['params'][0]['fromBlock'])->toBe('0x3a98')
        ->and($requests[2]['params'][0]['toBlock'])->toBe('0x61a7')
        ->and($requests[0]['params'][0]['topics'][2])->toHaveCount(100)
        ->and($requests[1]['params'][0]['topics'][2])->toHaveCount(1)
        ->and($transactions)->toHaveCount(1)
        ->and($transactions[0]->toAddress)->toBe($addresses[0])
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
