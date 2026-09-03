<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class InfuraProvider implements BlockchainProvider
{
    private const BLOCK_RANGE = 10000;

    private const TRANSFER_EVENT_SIGNATURE = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(
        private readonly string $network,
        private readonly string $usdtContract,
        private readonly ?string $projectId = null,
        private readonly ?string $projectSecret = null,
        private readonly string $infuraNetwork = 'mainnet',
    ) {}

    public function fetchTransactions(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $currentBlock = $this->currentBlockNumber();
        $transactions = [];

        foreach ($addresses as $address) {
            $transactions = array_merge($transactions, $this->fetchAddressLogs($address, $currentBlock));
        }

        return $transactions;
    }

    public function network(): string
    {
        return $this->network;
    }

    private function fetchAddressLogs(string $address, int $currentBlock): array
    {
        if ($this->projectId === null || $this->projectId === '') {
            return [];
        }

        $toTopic = '0x'.str_pad(ltrim(strtolower($address), '0x'), 64, '0', STR_PAD_LEFT);

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'eth_getLogs',
            'params' => [[
                'address' => strtolower($this->usdtContract),
                'fromBlock' => '0x'.dechex(max(0, $currentBlock - self::BLOCK_RANGE + 1)),
                'toBlock' => '0x'.dechex($currentBlock),
                'topics' => [self::TRANSFER_EVENT_SIGNATURE, null, $toTopic],
            ]],
            'id' => 1,
        ];

        $response = $this->rpc($payload);
        $logs = $response['result'] ?? [];

        $transactions = [];

        foreach ($logs as $log) {
            $logBlock = hexdec($log['blockNumber'] ?? '0x0');
            $confirmations = max(0, $currentBlock - $logBlock + 1);
            $rawValue = $this->hexToDec($log['data'] ?? '0x0');

            $transactions[] = new BlockchainTransaction(
                network: $this->network,
                txHash: (string) ($log['transactionHash'] ?? ''),
                toAddress: $address,
                amount: bcdiv($rawValue, '1000000', 6),
                confirmations: $confirmations,
                tokenContract: $this->usdtContract,
            );
        }

        return $transactions;
    }

    private function currentBlockNumber(): int
    {
        $response = $this->rpc([
            'jsonrpc' => '2.0',
            'method' => 'eth_blockNumber',
            'params' => [],
            'id' => 2,
        ]);

        return (int) hexdec($response['result'] ?? '0x0');
    }

    private function rpc(array $payload): array
    {
        $url = "https://{$this->infuraNetwork}.infura.io/v3/{$this->projectId}";

        $http = Http::timeout(30);

        if ($this->projectSecret) {
            $http = $http->withBasicAuth($this->projectId, $this->projectSecret);
        }

        $response = $http->post($url, $payload);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Infura API returned an error: '.$response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new InvalidArgumentException('Infura API returned an error: '.($data['error']['message'] ?? json_encode($data['error'])));
        }

        return $data;
    }

    private function hexToDec(string $hex): string
    {
        $hex = ltrim($hex, '0x');
        $dec = '0';

        for ($i = 0; $i < strlen($hex); $i++) {
            $dec = bcadd(bcmul($dec, '16', 0), (string) hexdec($hex[$i]), 0);
        }

        return $dec;
    }
}
