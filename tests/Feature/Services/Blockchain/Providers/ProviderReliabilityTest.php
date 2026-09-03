<?php

use App\Services\Blockchain\Providers\BlockCypherProvider;
use App\Services\Blockchain\Providers\InfuraProvider;
use App\Services\Blockchain\Providers\TronGridProvider;
use Illuminate\Support\Facades\Http;

test('infura scans no more than its latest ten thousand blocks', function () {
    Http::fakeSequence()
        ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => '0x2710'])
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

    $provider = new InfuraProvider('usdt_erc20', '0xcontract', 'project-id', 'secret');
    $provider->fetchTransactions(['0x1234']);

    $requests = Http::recorded();
    expect($requests)->toHaveCount(2)
        ->and($requests[1][0]->data()['params'][0]['fromBlock'])->toBe('0x1')
        ->and($requests[1][0]->data()['params'][0]['toBlock'])->toBe('0x2710');
});

test('infura raises json rpc errors returned with a successful http status', function () {
    Http::fakeSequence()
        ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => '0x2710'])
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'range exceeds limit']]);

    $provider = new InfuraProvider('usdt_erc20', '0xcontract', 'project-id', 'secret');

    expect(fn () => $provider->fetchTransactions(['0x1234']))
        ->toThrow(InvalidArgumentException::class, 'range exceeds limit');
});

test('blockcypher raises provider http failures', function () {
    Http::fake(['api.blockcypher.com/*' => Http::response(['error' => 'rate limited'], 429)]);

    $provider = new BlockCypherProvider('bitcoin', 'btc', 'token');

    expect(fn () => $provider->fetchTransactions(['btc-address']))
        ->toThrow(InvalidArgumentException::class, 'rate limited');
});

test('trongrid raises provider http failures', function () {
    Http::fake(['api.trongrid.io/*' => Http::response(['error' => 'rate limited'], 429)]);

    $provider = new TronGridProvider('usdt_trc20', 'contract', 'api-key');

    expect(fn () => $provider->fetchTransactions(['tron-address']))
        ->toThrow(InvalidArgumentException::class, 'rate limited');
});
