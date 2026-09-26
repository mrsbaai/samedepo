<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use App\Support\Network;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

class TronscanProvider implements BlockchainProvider
{
    public function __construct(
        private readonly string $network,
        private readonly string $contract,
        private readonly string $baseUrl = 'https://apilist.tronscanapi.com',
        private readonly ?string $apiKey = null,
    ) {}

    public function fetchTransactions(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $transactions = [];
        $lastIndex = count($addresses) - 1;

        // The scanner passes id-keyed arrays; positional logic needs 0-based keys.
        foreach (array_values($addresses) as $index => $address) {
            $transactions = array_merge($transactions, $this->fetchAddressTransactions($address));

            if ($index < $lastIndex) {
                Sleep::for(250)->milliseconds();
            }
        }

        return $transactions;
    }

    private function fetchAddressTransactions(string $address): array
    {
        $http = Http::baseUrl(rtrim($this->baseUrl, '/'))->timeout(30);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $http = $http->withHeaders(['TRON-PRO-API-KEY' => $this->apiKey]);
        }

        $response = $http->get('/api/transfer/trc20', [
            'address' => $address,
            'trc20Id' => $this->contract,
            'start' => 0,
            'limit' => 50,
            'direction' => 2,
            'reverse' => 'true',
        ]);

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)
            || (int) ($data['code'] ?? 0) !== 200
            || ! is_array($data['data'] ?? null)) {
            throw new InvalidArgumentException('Tronscan API returned an error: '.$response->body());
        }

        $transactions = [];
        $confirmedCount = Network::confirmations($this->network);

        foreach ($data['data'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['to'] ?? null) !== $address) {
                continue;
            }

            if (($item['contract_ret'] ?? null) !== 'SUCCESS' || (int) ($item['revert'] ?? 1) !== 0) {
                continue;
            }

            $decimals = (int) ($item['decimals'] ?? 6);
            $rawValue = (string) ($item['amount'] ?? '0');

            $transactions[] = new BlockchainTransaction(
                network: $this->network,
                txHash: (string) ($item['hash'] ?? ''),
                toAddress: $address,
                amount: bcdiv($rawValue, bcpow('10', (string) $decimals, 0), 6),
                confirmations: (int) ($item['confirmed'] ?? 0) === 1 ? $confirmedCount : 0,
                tokenContract: $this->contract,
            );
        }

        return $transactions;
    }

    public function network(): string
    {
        return $this->network;
    }
}
