<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\RemoteBlockchainBroadcaster;
use App\Support\Network;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function bindRemoteSigner(): void
{
    app()->bind(BlockchainBroadcaster::class, fn () => new RemoteBlockchainBroadcaster('https://signer.test', 'key'));
}

function fakeSigner(?callable $dryRunResponse = null, string $balance = '10.00000000'): void
{
    Http::fake(function (Request $request) use ($dryRunResponse, $balance) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if (in_array($path, ['/withdraw', '/sweep', '/topup'], true)) {
            if ($dryRunResponse !== null) {
                return $dryRunResponse($request, $path);
            }

            return Http::response(['data' => [
                'dry_run' => true,
                'ok' => true,
                'tx_hash' => '0xdry'.str_replace('/', '', $path),
                'checks' => [['name' => 'sender', 'ok' => true, 'detail' => 'ok']],
            ]]);
        }

        if ($path === '/balance') {
            return Http::response(['data' => ['balance' => $balance]]);
        }

        if ($path === '/fee') {
            return Http::response(['data' => ['fee' => '0.00100000']]);
        }

        return null; // let other fakes (e.g. TronSave) answer
    });
}

function fakeTronSave(): void
{
    Http::fake([
        'https://api.tronsave.io/v2/user-info' => Http::response(['error' => false, 'data' => ['balance' => '50000000']]),
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'data' => ['unitPrice' => 64, 'estimateTrx' => '4160000', 'availableResource' => 100000]]),
        'https://api.tronsave.io/*' => Http::response(['error' => false, 'data' => []]),
    ]);
}

function seedNetwork(string $network, int $i): void
{
    TreasuryWallet::factory()->create([
        'network' => $network,
        'derivation_index' => 0,
        'address' => 'treasury-'.$network,
    ]);

    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => $network,
        'derivation_index' => 10 + $i,
        'address' => 'deposit-'.$network,
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $network,
        'status' => 'credited',
        'swept_at' => null,
    ]);
}

test('every network dry-runs withdraw, sweep, and topup without broadcasting', function () {
    bindRemoteSigner();
    fakeSigner();
    fakeTronSave();

    foreach (Network::keys() as $i => $network) {
        seedNetwork($network, $i);
    }

    $this->artisan('app:settlement-dry-run')->assertSuccessful();

    $broadcastPaths = 0;
    Http::assertSent(function (Request $request) use (&$broadcastPaths) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (! in_array($path, ['/withdraw', '/sweep', '/topup'], true)) {
            return true;
        }
        $broadcastPaths++;

        return ($request['dry_run'] ?? false) === true;
    });
    expect($broadcastPaths)->toBeGreaterThanOrEqual(9 + 9 + 5); // 9 withdrawals + 9 sweeps + 5 top-ups

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/v2/buy-resource'));
});

test('a failing check exits 1 and names the failed check', function () {
    bindRemoteSigner();
    seedNetwork('usdt_erc20', 0);
    fakeSigner(fn () => Http::response(['data' => [
        'dry_run' => true,
        'ok' => false,
        'tx_hash' => '0xdry',
        'checks' => [['name' => 'base_fee', 'ok' => false, 'detail' => 'gasPrice under base fee']],
    ]]));
    fakeTronSave();

    $this->artisan('app:settlement-dry-run', ['--network' => ['usdt_erc20']])
        ->expectsOutputToContain('base_fee')
        ->assertFailed();
});

test('an empty treasury skips instead of failing', function () {
    bindRemoteSigner();
    seedNetwork('bitcoin', 0);
    fakeSigner(balance: '0.00000000');
    fakeTronSave();

    $this->artisan('app:settlement-dry-run', ['--network' => ['bitcoin']])
        ->expectsOutputToContain('treasury empty')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/withdraw'));
});

test('a zero token treasury reports PARTIAL when only the transfer simulation fails', function () {
    bindRemoteSigner();
    seedNetwork('usdt_erc20', 0);
    fakeSigner(fn (Request $request, string $path) => $path === '/withdraw'
        ? Http::response(['data' => [
            'dry_run' => true,
            'ok' => false,
            'tx_hash' => '0xdry',
            'checks' => [
                ['name' => 'sender', 'ok' => true, 'detail' => 'ok'],
                ['name' => 'eth_call', 'ok' => false, 'detail' => 'returned false'],
                ['name' => 'balance', 'ok' => true, 'detail' => 'ok'],
            ],
        ]])
        : Http::response(['data' => ['dry_run' => true, 'ok' => true, 'tx_hash' => '0xdry', 'checks' => []]]),
        balance: '0.00000000');
    fakeTronSave();

    $this->artisan('app:settlement-dry-run', ['--network' => ['usdt_erc20']])
        ->expectsOutputToContain('PARTIAL')
        ->assertSuccessful();
});

test('a zero token treasury still FAILs on non-simulation check failures', function () {
    bindRemoteSigner();
    seedNetwork('usdt_erc20', 0);
    fakeSigner(fn (Request $request, string $path) => $path === '/withdraw'
        ? Http::response(['data' => [
            'dry_run' => true,
            'ok' => false,
            'tx_hash' => '0xdry',
            'checks' => [
                ['name' => 'eth_call', 'ok' => false, 'detail' => 'returned false'],
                ['name' => 'balance', 'ok' => false, 'detail' => 'required 0.001 available 0'],
            ],
        ]])
        : Http::response(['data' => ['dry_run' => true, 'ok' => true, 'tx_hash' => '0xdry', 'checks' => []]]),
        balance: '0.00000000');
    fakeTronSave();

    $this->artisan('app:settlement-dry-run', ['--network' => ['usdt_erc20']])
        ->expectsOutputToContain('required 0.001 available 0')
        ->assertFailed();
});

test('an unavailable balance is a FAIL not a SKIP', function () {
    bindRemoteSigner();
    seedNetwork('usdt_erc20', 0);
    Http::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/balance'
        ? Http::response('unavailable', 503)
        : Http::response(['data' => ['fee' => '0.00100000', 'dry_run' => true, 'ok' => true, 'tx_hash' => '0xdry', 'checks' => []]]));
    fakeTronSave();

    $this->artisan('app:settlement-dry-run', ['--network' => ['usdt_erc20']])
        ->expectsOutputToContain('balance unavailable')
        ->assertFailed();
});

test('topup uses an unfunded deposit address when nothing holds funds', function () {
    bindRemoteSigner();
    TreasuryWallet::factory()->create([
        'network' => 'usdt_erc20',
        'derivation_index' => 0,
        'address' => 'treasury-usdt_erc20',
    ]);

    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_erc20',
        'derivation_index' => 42,
        'address' => '0xunfunded',
    ]);

    fakeSigner(balance: '0.00000000');
    fakeTronSave();

    $this->artisan('app:settlement-dry-run', ['--network' => ['usdt_erc20']])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/topup'
        && $request['destination_index'] === 42
        && ($request['dry_run'] ?? false) === true);
});

test('a token sweep short on gas passes with the provisioning note', function () {
    bindRemoteSigner();
    seedNetwork('usdt_erc20', 0);
    fakeSigner(function (Request $request, string $path) {
        if ($path === '/sweep') {
            return Http::response(['error' => 'insufficient_gas', 'required' => '0.00100000', 'available' => '0.00000000'], 422);
        }

        return Http::response(['data' => ['dry_run' => true, 'ok' => true, 'tx_hash' => '0xdry', 'checks' => []]]);
    });
    fakeTronSave();

    $this->artisan('app:settlement-dry-run', ['--network' => ['usdt_erc20']])
        ->expectsOutputToContain('provisioned automatically')
        ->assertSuccessful();
});
