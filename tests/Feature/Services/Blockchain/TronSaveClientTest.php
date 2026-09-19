<?php

declare(strict_types=1);

use App\Services\Blockchain\Energy\TronSaveClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('userInfo returns balance and deposit address', function () {
    Http::fake([
        'https://api.tronsave.io/v2/user-info' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['id' => 'acc', 'balance' => '50000000', 'representAddress' => 'TRep', 'depositAddress' => 'TDep']]),
    ]);

    $info = (new TronSaveClient)->userInfo();

    expect($info['balance'])->toBe('50000000')
        ->and($info['depositAddress'])->toBe('TDep');

    Http::assertSent(fn ($request) => $request->hasHeader('apikey'));
});

test('estimate posts a MEDIUM energy order and returns data', function () {
    Http::fake([
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 4160000, 'availableResource' => 100000]]),
    ]);

    $estimate = (new TronSaveClient)->estimate('TReceiver', 77142, 3600);

    expect($estimate['unitPrice'])->toBe(64)
        ->and($estimate['estimateTrx'])->toBe(4160000)
        ->and($estimate['availableResource'])->toBe(100000);

    Http::assertSent(fn ($request) => $request->data()['resourceType'] === 'ENERGY'
        && $request->data()['resourceAmount'] === 77142
        && $request->data()['durationSec'] === 3600
        && $request->data()['unitPrice'] === 'MEDIUM'
        && $request->data()['options']['allowPartialFill'] === false
        && $request->data()['receiver'] === 'TReceiver');
});

test('buy posts options and returns the order id', function () {
    Http::fake([
        'https://api.tronsave.io/v2/buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['orderId' => 'order-123']]),
    ]);

    $orderId = (new TronSaveClient)->buy('TReceiver', 77142, 3600, 90);

    expect($orderId)->toBe('order-123');

    Http::assertSent(fn ($request) => $request->data()['options'] === [
        'allowPartialFill' => false,
        'preventDuplicateIncompleteOrders' => true,
        'maxPriceAccepted' => 90,
    ]);
});

test('order returns order details', function () {
    Http::fake([
        'https://api.tronsave.io/v2/order/*' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['id' => 'order-123', 'fulfilledPercent' => 100, 'payoutAmount' => 4160000, 'price' => 64, 'delegates' => [['delegator' => 'TDel', 'amount' => 77142, 'txid' => 'abc123']]]]),
    ]);

    $order = (new TronSaveClient)->order('order-123');

    expect($order['fulfilledPercent'])->toBe(100)
        ->and($order['payoutAmount'])->toBe(4160000)
        ->and($order['delegates'][0]['txid'])->toBe('abc123');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/order/order-123'));
});

test('a non-2xx response returns null and logs', function () {
    Log::spy();
    Http::fake([
        'https://api.tronsave.io/v2/user-info' => Http::response('server error', 500),
    ]);

    expect((new TronSaveClient)->userInfo())->toBeNull();

    Log::shouldHaveReceived('warning')
        ->with('tronsave.request_failed', Mockery::on(fn ($context) => $context['path'] === '/v2/user-info' && $context['status'] === 500));
});

test('an error:true body returns null', function () {
    Log::spy();
    Http::fake([
        'https://api.tronsave.io/v2/user-info' => Http::response(['error' => true, 'message' => 'TSAS:107 INVALID_API_KEY']),
    ]);

    expect((new TronSaveClient)->userInfo())->toBeNull();

    Log::shouldHaveReceived('warning')->with('tronsave.request_failed', Mockery::any());
});

test('a connection exception returns null', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect((new TronSaveClient)->userInfo())->toBeNull();

    Log::shouldHaveReceived('warning')
        ->with('tronsave.request_failed', Mockery::on(fn ($context) => str_contains($context['error'], 'connection_failed')));
});
