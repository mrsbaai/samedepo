<?php

declare(strict_types=1);

use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\RemoteBlockchainBroadcaster;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function remoteWithdrawal(string $network): Withdrawal
{
    $owner = User::factory()->create(['role' => 'owner']);

    TreasuryWallet::firstOrCreate(
        ['network' => $network],
        ['derivation_index' => 0, 'address' => 'treasury-'.$network, 'available_funds' => '1000.00000000'],
    );

    return Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => $network,
        'gross_amount' => '100.00000000',
        'amount_sent' => '98.02000000',
        'network_fee' => '1.98000000',
        'network_fee_native' => $network === 'bitcoin' ? '0.00024000' : '6.00000000',
        'destination_address' => 'destination-'.$network,
        'mode' => 'instant',
        'status' => 'pending',
    ]);
}

test('broadcast withdrawal sends deducted amount and native fee for tokens', function () {
    $withdrawal = remoteWithdrawal('usdt_trc20');

    Http::fake([
        'https://signer.test/withdraw' => Http::response(['data' => ['tx_hash' => 'token-withdrawal-tx']]),
    ]);

    $hash = (new RemoteBlockchainBroadcaster('https://signer.test', 'secret'))->broadcastWithdrawal($withdrawal);

    expect($hash)->toBe('token-withdrawal-tx');
    Http::assertSent(fn ($request) => $request->url() === 'https://signer.test/withdraw'
        && $request['index'] === 0
        && $request['amount'] === '98.02000000'
        && $request['fee'] === '6.00000000');
});

test('it preserves structured signer failures', function () {
    Log::spy();
    Http::fake(['https://signer.test/topup' => Http::response([
        'error' => 'insufficient_gas',
        'required' => '0.00001000',
        'available' => '0.00000300',
    ], 422)]);
    $broadcaster = new RemoteBlockchainBroadcaster('https://signer.test', 'secret');

    expect($broadcaster->broadcastTopUp('usdt_erc20', 0, 2, '0.0003', '0.00001'))->toBeNull()
        ->and($broadcaster->lastError())->toBe('insufficient_gas: required 0.00001000 available 0.00000300');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => $message === 'signer.request_failed');
});

test('it handles signer connection failures without throwing', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('cURL error 7'));
    $broadcaster = new RemoteBlockchainBroadcaster('https://signer.test', 'secret');

    expect($broadcaster->broadcastTopUp('usdt_erc20', 0, 2, '0.0003', '0.00001'))->toBeNull()
        ->and($broadcaster->lastError())->toStartWith('connection_failed: ');
});

test('broadcast withdrawal adds fee to amount for bitcoin', function () {
    $withdrawal = remoteWithdrawal('bitcoin');

    Http::fake([
        'https://signer.test/withdraw' => Http::response(['data' => ['tx_hash' => 'btc-withdrawal-tx']]),
    ]);

    $hash = (new RemoteBlockchainBroadcaster('https://signer.test', 'secret'))->broadcastWithdrawal($withdrawal);

    expect($hash)->toBe('btc-withdrawal-tx');
    Http::assertSent(fn ($request) => $request->url() === 'https://signer.test/withdraw'
        && $request['amount'] === '98.02024000'
        && $request['fee'] === '0.00024000');
});
