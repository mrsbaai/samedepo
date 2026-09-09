<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\Withdrawal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RemoteBlockchainBroadcaster implements BlockchainBroadcaster, ReportsLastError
{
    private ?string $lastError = null;

    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
    ) {}

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        $tokenTransfer = in_array($withdrawal->network, ['usdt_erc20', 'usdt_trc20'], true);

        $response = $this->post('/fee', [
            'network' => $withdrawal->network,
            'token_transfer' => $tokenTransfer,
        ]);

        if ($response?->successful()) {
            return $response->json('data.fee');
        }

        return null;
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        $wallet = TreasuryWallet::where('network', $withdrawal->network)->first();

        if ($wallet === null) {
            return null;
        }

        $isBitcoin = $withdrawal->network === 'bitcoin';
        $amount = (string) $withdrawal->amount_sent;
        $fee = (string) ($withdrawal->network_fee_native ?? '0.00000000');

        // Bitcoin: BlockCypher subtracts the fee from amount, so we pass the total input value.
        if ($isBitcoin) {
            $amount = bcadd($amount, $fee, 8);
        }

        $response = $this->post('/withdraw', [
            'network' => $withdrawal->network,
            'index' => $wallet->derivation_index,
            'destination' => $withdrawal->destination_address,
            'amount' => $amount,
            'fee' => $fee,
        ]);

        if ($response?->successful()) {
            return $response->json('data.tx_hash');
        }

        return null;
    }

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        $address = $sweep->deposit_address_id !== null
            ? DepositAddress::find($sweep->deposit_address_id)
            : Deposit::with('depositAddress')->find($sweep->deposit_id)?->depositAddress;
        $wallet = TreasuryWallet::where('network', $sweep->network)->first();

        if ($address === null || $wallet === null) {
            return null;
        }

        $fee = $this->post('/fee', [
            'network' => $sweep->network,
            'token_transfer' => in_array($sweep->network, ['usdt_erc20', 'usdt_trc20'], true),
        ]);

        if (! $fee?->successful()) {
            return null;
        }

        // Bitcoin: the deposit address holds exactly the swept amount, so the
        // miner fee comes out of the amount (the signer sends amount - fee).
        $response = $this->post('/sweep', [
            'network' => $sweep->network,
            'source_index' => $address->derivation_index,
            'destination_index' => $wallet->derivation_index,
            'amount' => (string) $sweep->amount,
            'fee' => (string) $fee->json('data.fee'),
        ]);

        if ($response?->successful()) {
            return $response->json('data.tx_hash');
        }

        return null;
    }

    public function getNativeBalance(string $network, int $index): ?string
    {
        $response = $this->post('/balance', [
            'network' => $network,
            'index' => $index,
        ]);

        if ($response?->successful()) {
            return $response->json('data.balance');
        }

        return null;
    }

    public function getTronResource(int $index): ?array
    {
        $response = $this->post('/tron-resource', [
            'index' => $index,
        ]);

        if ($response?->successful()) {
            return $response->json('data');
        }

        return null;
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        $response = $this->post('/receipt', [
            'network' => $network,
            'tx_hash' => $txHash,
        ]);

        if ($response?->successful()) {
            return $response->json('data');
        }

        return null;
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        $response = $this->post('/fee', [
            'network' => $network,
            'token_transfer' => $tokenTransfer,
        ]);

        if ($response?->successful()) {
            return $response->json('data.fee');
        }

        return null;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        $wallet = TreasuryWallet::where('network', $payout->network)->first();

        if ($wallet === null) {
            return null;
        }

        $isBitcoin = $payout->network === 'bitcoin';
        $amount = (string) $payout->amount;
        $fee = (string) ($payout->network_fee ?? '0.00000000');

        if ($isBitcoin) {
            $amount = bcadd($amount, $fee, 8);
        }

        $response = $this->post('/withdraw', [
            'network' => $payout->network,
            'index' => $wallet->derivation_index,
            'destination' => $payout->destination_address,
            'amount' => $amount,
            'fee' => $fee,
        ]);

        if ($response?->successful()) {
            return $response->json('data.tx_hash');
        }

        return null;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        $response = $this->post('/topup', [
            'network' => $network,
            'source_index' => $sourceIndex,
            'destination_index' => $destinationIndex,
            'amount' => $amount,
            'fee' => $fee,
        ]);

        if ($response?->successful()) {
            return $response->json('data.tx_hash');
        }

        return null;
    }

    private function post(string $path, array $payload): ?Response
    {
        $this->lastError = null;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $this->apiKey);

        try {
            $response = Http::withHeaders([
                'X-Signer-Timestamp' => $timestamp,
                'X-Signer-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->url}{$path}", $payload);
        } catch (ConnectionException $exception) {
            $this->lastError = 'connection_failed: '.$exception->getMessage();
            Log::error('signer.request_failed', ['path' => $path, 'payload' => $payload, 'error' => $this->lastError]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = $this->describeFailure($response);
            Log::error('signer.request_failed', [
                'path' => $path,
                'status' => $response->status(),
                'payload' => $payload,
                'body' => mb_substr($response->body(), 0, 1000),
            ]);
        }

        return $response;
    }

    private function describeFailure(Response $response): string
    {
        $json = $response->json();
        $error = is_array($json) ? ($json['error'] ?? null) : null;

        return match ($error) {
            'insufficient_gas' => sprintf('insufficient_gas: required %s available %s', $json['required'] ?? '?', $json['available'] ?? '?'),
            'broadcast_failed' => 'broadcast_failed: '.($json['message'] ?? 'unknown'),
            default => sprintf('http_%d: %s', $response->status(), mb_substr($response->body(), 0, 200)),
        };
    }
}
