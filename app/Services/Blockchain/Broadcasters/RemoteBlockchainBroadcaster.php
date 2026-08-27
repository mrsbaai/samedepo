<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

use App\Models\Deposit;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\Withdrawal;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class RemoteBlockchainBroadcaster implements BlockchainBroadcaster
{
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
    ) {}

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        $tokenTransfer = in_array($withdrawal->network, ['usdt_erc20', 'usdt_trc20'], true);

        $response = $this->post('/fee', [
            'network' => $withdrawal->network,
            'token_transfer' => $tokenTransfer,
        ]);

        if ($response->successful()) {
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

        $response = $this->post('/withdraw', [
            'network' => $withdrawal->network,
            'index' => $wallet->derivation_index,
            'destination' => $withdrawal->destination_address,
            'amount' => (string) $withdrawal->gross_amount,
            'fee' => (string) $withdrawal->network_fee,
        ]);

        if ($response->successful()) {
            return $response->json('data.tx_hash');
        }

        return null;
    }

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        $deposit = Deposit::with('depositAddress')->find($sweep->deposit_id);
        $wallet = TreasuryWallet::where('network', $sweep->network)->first();

        if ($deposit === null || $deposit->depositAddress === null || $wallet === null) {
            return null;
        }

        $fee = $this->post('/fee', [
            'network' => $sweep->network,
            'token_transfer' => in_array($sweep->network, ['usdt_erc20', 'usdt_trc20'], true),
        ]);

        if (! $fee->successful()) {
            return null;
        }

        $response = $this->post('/sweep', [
            'network' => $sweep->network,
            'source_index' => $deposit->depositAddress->derivation_index,
            'destination_index' => $wallet->derivation_index,
            'amount' => (string) $sweep->amount,
            'fee' => $fee->json('data.fee'),
        ]);

        if ($response->successful()) {
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

        if ($response->successful()) {
            return $response->json('data.balance');
        }

        return null;
    }

    public function getTronResource(int $index): ?array
    {
        $response = $this->post('/tron-resource', [
            'index' => $index,
        ]);

        if ($response->successful()) {
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

        if ($response->successful()) {
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

        if ($response->successful()) {
            return $response->json('data.fee');
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

        if ($response->successful()) {
            return $response->json('data.tx_hash');
        }

        return null;
    }

    private function post(string $path, array $payload): Response
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        $message = "{$timestamp}.{$body}";
        $signature = hash_hmac('sha256', $message, $this->apiKey);

        return Http::withHeaders([
            'X-Signer-Timestamp' => $timestamp,
            'X-Signer-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post("{$this->url}{$path}", $payload);
    }
}
