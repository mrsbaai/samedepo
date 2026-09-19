<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\Withdrawal;
use App\Support\Network;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RemoteBlockchainBroadcaster implements BlockchainBroadcaster, EstimatesTransferFee, ReportsLastError
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
        return $this->estimateTransferFee(
            $withdrawal->network,
            Network::isToken($withdrawal->network),
            $withdrawal->destination_address,
        );
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        $wallet = TreasuryWallet::where('network', $withdrawal->network)->first();

        if ($wallet === null) {
            return null;
        }

        $isNative = Network::isNative($withdrawal->network);
        $amount = (string) $withdrawal->amount_sent;
        $fee = $this->sendFeeLimit($withdrawal->network, (string) ($withdrawal->network_fee_native ?? '0.00000000'));

        // Native sends: the chain subtracts the fee from amount, so we pass the total input value.
        if ($isNative) {
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
            'token_transfer' => Network::isToken($sweep->network),
        ]);

        if (! $fee?->successful()) {
            return null;
        }

        // Native sweeps: the deposit address holds exactly the swept amount, so the
        // miner fee comes out of the amount (the signer sends amount - fee).
        $response = $this->post('/sweep', [
            'network' => $sweep->network,
            'source_index' => $address->derivation_index,
            'destination_index' => $wallet->derivation_index,
            'amount' => (string) $sweep->amount,
            'fee' => $this->sendFeeLimit($sweep->network, (string) $fee->json('data.fee')),
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

    public function getTokenBalance(string $network, int $index): ?string
    {
        $response = $this->post('/balance', [
            'network' => $network,
            'index' => $index,
            'token' => true,
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

    public function estimateTransferFee(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?string
    {
        $response = $this->post('/fee', array_filter([
            'network' => $network,
            'token_transfer' => $tokenTransfer,
            'destination' => $destination,
            'source_index' => $sourceIndex,
        ], fn ($value) => $value !== null));

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

        $isNative = Network::isNative($payout->network);
        $amount = (string) $payout->amount;
        $fee = $this->sendFeeLimit($payout->network, (string) ($payout->network_fee ?? '0.00000000'));

        if ($isNative) {
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

    // TRC20 token sends: fee doubles as fee_limit, which caps usable energy
    // (delegated energy included). Never let the charged/rental price be the cap.
    private function sendFeeLimit(string $network, string $fee): string
    {
        if (Network::family($network) !== 'tron') {
            return $fee;
        }

        $cap = bcadd((string) config('blockchain.trc20_fee_limit_trx'), '0', 8);

        return bccomp($fee, $cap, 8) < 0 ? $cap : $fee;
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
