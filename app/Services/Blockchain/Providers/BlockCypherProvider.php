<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Support\Facades\Http;

class BlockCypherProvider implements BlockchainProvider
{
    public function __construct(
        private readonly string $network,
        private readonly string $coinSymbol,
        private readonly ?string $token = null,
        private readonly string $apiNetwork = 'main',
    ) {}

    public function fetchTransactions(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $transactions = [];

        foreach ($addresses as $address) {
            $transactions = array_merge($transactions, $this->fetchAddressTransactions($address));
        }

        return $transactions;
    }

    public function network(): string
    {
        return $this->network;
    }

    private function fetchAddressTransactions(string $address): array
    {
        $url = "https://api.blockcypher.com/v1/{$this->coinSymbol}/{$this->apiNetwork}/addrs/{$address}/full";
        $params = ['limit' => 50];

        if ($this->token) {
            $params['token'] = $this->token;
        }

        $response = Http::get($url, $params)->json();
        $txs = $response['txs'] ?? [];

        $transactions = [];

        foreach ($txs as $tx) {
            $confirmations = (int) ($tx['confirmations'] ?? 0);
            $hash = (string) ($tx['hash'] ?? '');
            $outputs = $tx['outputs'] ?? [];

            $amount = $this->sumOutputsToAddress($outputs, $address);

            if ($amount <= 0) {
                continue;
            }

            $transactions[] = new BlockchainTransaction(
                network: $this->network,
                txHash: $hash,
                toAddress: $address,
                amount: bcdiv((string) $amount, '100000000', 8),
                confirmations: $confirmations,
            );
        }

        return $transactions;
    }

    private function sumOutputsToAddress(array $outputs, string $address): int
    {
        $total = 0;

        foreach ($outputs as $output) {
            $addresses = $output['addresses'] ?? [];

            if (! in_array($address, $addresses, true)) {
                continue;
            }

            $total += (int) ($output['value'] ?? 0);
        }

        return $total;
    }
}
