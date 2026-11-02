<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\DepositAddress;
use App\Models\GasTopup;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\RemoteBlockchainBroadcaster;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\StuckTransactionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function bindStuckSigner(array $receipts = [], ?callable $speedupResponse = null): void
{
    app()->bind(BlockchainBroadcaster::class, fn () => new RemoteBlockchainBroadcaster('https://signer.test', 'key'));

    Http::fake(function (Request $request) use ($receipts, $speedupResponse) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($path === '/receipt') {
            $hash = $request['tx_hash'];

            return Http::response(['data' => $receipts[$hash] ?? ['status' => 'pending', 'seen' => true, 'confirmations' => 0]]);
        }

        if ($path === '/speedup') {
            return $speedupResponse !== null
                ? $speedupResponse($request)
                : Http::response(['data' => ['status' => 'replaced', 'tx_hash' => '0xnewhash', 'gas_price_gwei' => '2.5', 'old_gas_price_gwei' => '2']]);
        }

        return null;
    });
}

function treasuryFor(string $network, int $index = 0): void
{
    TreasuryWallet::factory()->create([
        'network' => $network,
        'derivation_index' => $index,
        'address' => 'treasury-'.$network,
    ]);
}

function stuckSweep(string $network = 'usdt_erc20', int $index = 5): TreasurySweep
{
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => $network,
        'derivation_index' => $index,
        'address' => 'deposit-'.$network,
    ]);

    return TreasurySweep::create([
        'deposit_address_id' => $address->id,
        'network' => $network,
        'amount' => '10.00000000',
        'status' => 'pending',
        'tx_hash' => '0xold'.$index,
        'last_broadcast_at' => now()->subMinutes(30),
    ]);
}

function stuckWithdrawal(string $network = 'usdt_erc20', array $overrides = []): Withdrawal
{
    $owner = User::factory()->create(['role' => 'owner']);

    return Withdrawal::factory()->create(array_merge([
        'user_id' => $owner->id,
        'network' => $network,
        'status' => 'sent',
        'tx_hash' => '0xoldw',
        'sent_at' => now()->subMinutes(30),
        'last_broadcast_at' => now()->subMinutes(30),
    ], $overrides));
}

function runService(): void
{
    app(StuckTransactionService::class)->run();
}

function assertNoSpeedup(): void
{
    Http::assertNotSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/speedup');
}

test('a stuck pending sweep is sped up with the deposit index', function () {
    bindStuckSigner();
    treasuryFor('usdt_erc20');
    $sweep = stuckSweep('usdt_erc20', 5);

    runService();

    Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/speedup'
        && $request['network'] === 'usdt_erc20'
        && $request['index'] === 5
        && $request['tx_hash'] === '0xold5');

    $sweep->refresh();
    expect($sweep->tx_hash)->toBe('0xnewhash')
        ->and($sweep->replaced_tx_hashes)->toBe(['0xold5'])
        ->and($sweep->last_broadcast_at->gt(now()->subMinute()))->toBeTrue();
});

test('a stuck pending withdrawal is sped up with the treasury index', function () {
    bindStuckSigner();
    treasuryFor('usdt_erc20', 3);
    $withdrawal = stuckWithdrawal('usdt_erc20');

    runService();

    Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/speedup'
        && $request['index'] === 3 && $request['tx_hash'] === '0xoldw');
    expect($withdrawal->refresh()->tx_hash)->toBe('0xnewhash');
});

test('a stuck top-up is sped up with its source index', function () {
    bindStuckSigner();
    treasuryFor('usdt_erc20');
    $wallet = TreasuryWallet::where('network', 'usdt_erc20')->first();
    $topup = GasTopup::create([
        'treasury_wallet_id' => $wallet->id,
        'network' => 'usdt_erc20', 'kind' => 'topup', 'status' => 'broadcast',
        'recipient_address' => '0xdep', 'recipient_index' => 9, 'source_index' => 7,
        'amount' => '0.00100000', 'tx_hash' => '0xoldt', 'is_open' => 'open',
        'broadcasted_at' => now()->subMinutes(30), 'last_broadcast_at' => now()->subMinutes(30),
    ]);

    runService();

    Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/speedup'
        && $request['index'] === 7 && $request['tx_hash'] === '0xoldt');
    expect($topup->refresh()->tx_hash)->toBe('0xnewhash');
});

test('a stuck payout is sped up with the treasury index', function () {
    bindStuckSigner();
    treasuryFor('usdt_erc20', 2);
    $payout = TreasuryPayout::create([
        'created_by' => User::factory()->create(['role' => 'admin'])->id,
        'network' => 'usdt_erc20', 'destination_address' => '0xdest', 'amount' => '5.00000000',
        'status' => 'sent', 'tx_hash' => '0xoldp',
        'sent_at' => now()->subMinutes(30), 'last_broadcast_at' => now()->subMinutes(30),
    ]);

    runService();

    Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/speedup'
        && $request['index'] === 2 && $request['tx_hash'] === '0xoldp');
    expect($payout->refresh()->tx_hash)->toBe('0xnewhash');
});

test('a broadcast younger than ten minutes is left alone', function () {
    bindStuckSigner();
    treasuryFor('usdt_erc20');
    stuckWithdrawal('usdt_erc20', [
        'sent_at' => now()->subMinutes(5),
        'last_broadcast_at' => now()->subMinutes(5),
    ]);

    runService();

    assertNoSpeedup();
});

test('non-EVM networks are never sped up', function () {
    bindStuckSigner();
    treasuryFor('bitcoin');
    stuckSweep('bitcoin', 4);

    runService();

    assertNoSpeedup();
});

test('a confirmed receipt is left for the normal pollers', function () {
    bindStuckSigner(['0xoldw' => ['status' => 'confirmed', 'fee' => '0.001', 'confirmations' => 20]]);
    treasuryFor('usdt_erc20');
    stuckWithdrawal('usdt_erc20');

    runService();

    assertNoSpeedup();
});

test('a mined replaced hash is adopted when the current hash is unseen', function () {
    bindStuckSigner([
        '0xcurrent' => ['status' => 'pending', 'seen' => false, 'confirmations' => 0],
        '0xold' => ['status' => 'confirmed', 'fee' => '0.001', 'confirmations' => 20],
    ]);
    treasuryFor('usdt_erc20');
    $withdrawal = stuckWithdrawal('usdt_erc20', [
        'tx_hash' => '0xcurrent',
        'replaced_tx_hashes' => ['0xold'],
        'last_broadcast_at' => now()->subMinutes(5),
    ]);

    runService();

    $withdrawal->refresh();
    expect($withdrawal->tx_hash)->toBe('0xold')
        ->and($withdrawal->replaced_tx_hashes)->toBe(['0xcurrent']);
    assertNoSpeedup();
});

test('a row with five replacements is not sped up again', function () {
    bindStuckSigner();
    treasuryFor('usdt_erc20');
    $withdrawal = stuckWithdrawal('usdt_erc20', [
        'replaced_tx_hashes' => ['0x1', '0x2', '0x3', '0x4', '0x5'],
    ]);

    runService();

    assertNoSpeedup();
});

test('a stale top-up seen in the mempool is not marked dropped', function () {
    Http::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/receipt'
        ? Http::response(['data' => ['status' => 'pending', 'seen' => true, 'confirmations' => 0]])
        : null);

    $topup = GasTopup::create([
        'treasury_wallet_id' => TreasuryWallet::factory()->create(['network' => 'usdt_erc20'])->id,
        'network' => 'usdt_erc20', 'kind' => 'topup', 'status' => 'broadcast',
        'recipient_address' => '0xdep', 'recipient_index' => 9,
        'amount' => '0.00100000', 'tx_hash' => '0xseen', 'is_open' => 'open',
        'broadcasted_at' => now()->subMinutes(31),
    ]);

    (new GasTreasuryService(new RemoteBlockchainBroadcaster('https://signer.test', 'key')))->pollTopups();

    expect($topup->refresh()->status)->toBe('broadcast');
});

test('a stale unseen top-up is still marked dropped', function () {
    Http::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/receipt'
        ? Http::response(['data' => ['status' => 'pending', 'seen' => false, 'confirmations' => 0]])
        : null);

    $topup = GasTopup::create([
        'treasury_wallet_id' => TreasuryWallet::factory()->create(['network' => 'usdt_erc20'])->id,
        'network' => 'usdt_erc20', 'kind' => 'topup', 'status' => 'broadcast',
        'recipient_address' => '0xdep', 'recipient_index' => 9,
        'amount' => '0.00100000', 'tx_hash' => '0xgone', 'is_open' => 'open',
        'broadcasted_at' => now()->subMinutes(31),
    ]);

    (new GasTreasuryService(new RemoteBlockchainBroadcaster('https://signer.test', 'key')))->pollTopups();

    expect($topup->refresh()->status)->toBe('failed');
});
