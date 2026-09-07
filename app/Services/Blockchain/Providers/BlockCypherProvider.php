<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

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
        $url = "https://api.blockcypher.com/v1/{$this->coinSymbol}/{$this->apiNetwork}/addrs/{$address}";
        $params = ['limit' => 50];

        if ($this->token) {
            $params['token'] = $this->token;
        }

        $response = Http::get($url, $params);

        if (! $response->successful()) {
            throw new InvalidArgumentException('BlockCypher API returned an error: '.$response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new InvalidArgumentException('BlockCypher API returned an error: '.$data['error']);
        }

        $references = [];

        foreach (['txrefs', 'unconfirmed_txrefs'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $references = array_merge($references, $data[$key]);
            }
        }

        $incoming = [];

        foreach ($references as $reference) {
            if (! is_array($reference) || (int) ($reference['tx_input_n'] ?? 0) !== -1) {
                continue;
            }

            $hash = (string) ($reference['tx_hash'] ?? '');
            $value = (int) ($reference['value'] ?? 0);

            if ($hash === '' || $value <= 0) {
                continue;
            }

            $incoming[$hash] ??= ['value' => 0, 'confirmations' => 0];
            $incoming[$hash]['value'] += $value;
            $incoming[$hash]['confirmations'] = max(
                $incoming[$hash]['confirmations'],
                (int) ($reference['confirmations'] ?? 0),
            );
        }

        $transactions = [];

        foreach ($incoming as $hash => $transaction) {
            $transactions[] = new BlockchainTransaction(
                network: $this->network,
                txHash: $hash,
                toAddress: $address,
                amount: bcdiv((string) $transaction['value'], '100000000', 8),
                confirmations: $transaction['confirmations'],
            );
        }

        return $transactions;
    }
}
