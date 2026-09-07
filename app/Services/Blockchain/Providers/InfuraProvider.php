<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Models\BlockchainScanState;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class InfuraProvider implements BlockchainProvider
{
    private const BLOCK_RANGE = 10000;

    private const RECIPIENT_BATCH_SIZE = 100;

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
        if ($addresses === [] || $this->projectId === null || $this->projectId === '') {
            return [];
        }

        $currentBlock = $this->currentBlockNumber();
        $state = BlockchainScanState::query()->where('network', $this->network)->first();
        $confirmationsRequired = max(0, (int) config("blockchain.confirmations.{$this->network}", 0));
        $overlap = max(0, $confirmationsRequired - 1);
        $fromBlock = $state?->last_scanned_block === null
            ? max(0, $currentBlock - self::BLOCK_RANGE + 1)
            : max(0, $state->last_scanned_block + 1 - $overlap);

        if ($fromBlock > $currentBlock) {
            return [];
        }

        $chunkEnd = min($currentBlock, $fromBlock + self::BLOCK_RANGE - 1);
        $topicsToAddresses = [];

        foreach ($addresses as $address) {
            $topicsToAddresses[$this->addressTopic($address)] = $address;
        }

        $transactions = [];

        foreach (array_chunk(array_keys($topicsToAddresses), self::RECIPIENT_BATCH_SIZE) as $recipientTopics) {
            $response = $this->rpc([
                'jsonrpc' => '2.0',
                'method' => 'eth_getLogs',
                'params' => [[
                    'address' => strtolower($this->usdtContract),
                    'fromBlock' => '0x'.dechex($fromBlock),
                    'toBlock' => '0x'.dechex($chunkEnd),
                    'topics' => [self::TRANSFER_EVENT_SIGNATURE, null, $recipientTopics],
                ]],
                'id' => 1,
            ]);

            foreach ($response['result'] ?? [] as $log) {
                $recipientTopic = strtolower((string) ($log['topics'][2] ?? ''));
                $address = $topicsToAddresses[$recipientTopic] ?? null;

                if ($address === null) {
                    continue;
                }

                $logBlock = hexdec($log['blockNumber'] ?? '0x0');
                $transactions[] = new BlockchainTransaction(
                    network: $this->network,
                    txHash: (string) ($log['transactionHash'] ?? ''),
                    toAddress: $address,
                    amount: bcdiv($this->hexToDec($log['data'] ?? '0x0'), '1000000', 6),
                    confirmations: max(0, $currentBlock - $logBlock + 1),
                    tokenContract: $this->usdtContract,
                );
            }
        }

        BlockchainScanState::query()->updateOrCreate(
            ['network' => $this->network],
            ['last_scanned_block' => $chunkEnd],
        );

        return $transactions;
    }

    public function network(): string
    {
        return $this->network;
    }

    private function addressTopic(string $address): string
    {
        return '0x'.str_pad(preg_replace('/^0x/i', '', strtolower($address)), 64, '0', STR_PAD_LEFT);
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
