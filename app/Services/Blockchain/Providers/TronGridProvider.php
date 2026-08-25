<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class TronGridProvider implements BlockchainProvider
{
    public function __construct(
        private readonly string $network,
        private readonly string $usdtContract,
        private readonly ?string $apiKey = null,
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
        if ($this->apiKey === null || $this->apiKey === '') {
            return [];
        }

        $http = Http::baseUrl('https://api.trongrid.io')
            ->withHeaders(['TRON-PRO-API-KEY' => $this->apiKey])
            ->timeout(30);

        $response = $http->get('/v1/accounts/'.$address.'/transactions/trc20', [
            'contract_address' => $this->usdtContract,
            'only_to' => 'true',
            'only_confirmed' => 'true',
            'limit' => 200,
        ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('TRONGrid API returned an error: '.$response->body());
        }

        $items = $response->json('data', []);
        $transactions = [];

        foreach ($items as $item) {
            $token = $item['token_info'] ?? [];

            if (($token['symbol'] ?? '') !== 'USDT') {
                continue;
            }

            if (strtolower($token['address'] ?? '') !== strtolower($this->usdtContract)) {
                continue;
            }

            $decimals = (int) ($token['decimals'] ?? 6);
            $rawValue = (string) ($item['value'] ?? '0');

            $transactions[] = new BlockchainTransaction(
                network: $this->network,
                txHash: (string) ($item['transaction_id'] ?? ''),
                toAddress: $address,
                amount: bcdiv($rawValue, bcpow('10', (string) $decimals, 0), 6),
                confirmations: 20, // TronGrid confirmed endpoint does not expose a count.
                tokenContract: $this->usdtContract,
            );
        }

        return $transactions;
    }
}
