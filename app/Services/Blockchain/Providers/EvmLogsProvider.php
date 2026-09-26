<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Models\BlockchainScanState;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use App\Support\Network;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

class EvmLogsProvider implements BlockchainProvider
{
    private const RECIPIENT_BATCH_SIZE = 100;

    private const MAX_RPC_ATTEMPTS = 4;

    private int $rpcCalls = 0;

    private const TRANSFER_EVENT_SIGNATURE = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(
        private readonly string $network,
        private readonly string $contract,
        private readonly ?string $rpcUrl = null,
        private readonly ?string $projectId = null,
        private readonly ?string $projectSecret = null,
        private readonly string $infuraNetwork = 'mainnet',
        private readonly int $tokenDecimals = 6,
        private readonly int $blockRange = 10000,
        private readonly int $maxChunksPerScan = 50,
    ) {}

    public function fetchTransactions(array $addresses): array
    {
        $this->rpcCalls = 0;

        if ($addresses === [] || ($this->rpcUrl === null && ($this->projectId === null || $this->projectId === ''))) {
            return [];
        }

        $currentBlock = $this->currentBlockNumber();
        $state = BlockchainScanState::query()->where('network', $this->network)->first();
        $confirmationsRequired = max(0, Network::confirmations($this->network));
        $overlap = max(0, $confirmationsRequired - 1);
        $fromBlock = $state?->last_scanned_block === null
            ? max(0, $currentBlock - $this->blockRange + 1)
            : max(0, $state->last_scanned_block + 1 - $overlap);

        if ($fromBlock > $currentBlock) {
            return [];
        }

        $topicsToAddresses = [];

        foreach ($addresses as $address) {
            $topicsToAddresses[$this->addressTopic($address)] = $address;
        }

        $recipientBatches = array_chunk(array_keys($topicsToAddresses), self::RECIPIENT_BATCH_SIZE);
        $transactions = [];
        $chunkStart = $fromBlock;

        for ($chunk = 0; $chunk < $this->maxChunksPerScan && $chunkStart <= $currentBlock; $chunk++) {
            $chunkEnd = min($currentBlock, $chunkStart + $this->blockRange - 1);

            try {
                foreach ($recipientBatches as $recipientTopics) {
                    $response = $this->rpc([
                        'jsonrpc' => '2.0',
                        'method' => 'eth_getLogs',
                        'params' => [[
                            'address' => strtolower($this->contract),
                            'fromBlock' => '0x'.dechex($chunkStart),
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
                            amount: bcdiv($this->hexToDec($log['data'] ?? '0x0'), bcpow('10', (string) $this->tokenDecimals, 0), 8),
                            confirmations: max(0, $currentBlock - $logBlock + 1),
                            tokenContract: $this->contract,
                        );
                    }
                }
            } catch (Throwable $exception) {
                // Progress is only persisted for fully fetched chunks, so a
                // failure here keeps the scan state at the last good chunk.
                if ($chunk === 0) {
                    throw $exception;
                }

                break;
            }

            BlockchainScanState::query()->updateOrCreate(
                ['network' => $this->network],
                ['last_scanned_block' => $chunkEnd],
            );

            $chunkStart = $chunkEnd + 1;
        }

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
        $url = $this->rpcUrl ?? "https://{$this->infuraNetwork}.infura.io/v3/{$this->projectId}";
        $http = Http::timeout(30);

        if ($this->projectSecret) {
            $http = $http->withBasicAuth($this->projectId, $this->projectSecret);
        }

        // Free RPC tiers reject bursts, so consecutive calls in one scan are paced.
        if ($this->rpcCalls++ > 0) {
            Sleep::for(250)->milliseconds();
        }

        for ($attempt = 1; ; $attempt++) {
            $response = $http->post($url, $payload);
            $rateLimited = $response->status() === 429 || $this->looksRateLimited($response->body());

            if (! $response->successful()) {
                if ($rateLimited && $attempt < self::MAX_RPC_ATTEMPTS) {
                    Sleep::for(2 ** ($attempt - 1))->seconds();

                    continue;
                }

                throw new InvalidArgumentException('EVM RPC returned an error: '.$response->body());
            }

            $data = $response->json();

            if (isset($data['error'])) {
                $message = (string) ($data['error']['message'] ?? json_encode($data['error']));

                if ($rateLimited && $attempt < self::MAX_RPC_ATTEMPTS) {
                    Sleep::for(2 ** ($attempt - 1))->seconds();

                    continue;
                }

                throw new InvalidArgumentException('EVM RPC returned an error: '.$message);
            }

            return $data;
        }
    }

    private function looksRateLimited(string $body): bool
    {
        $body = strtolower($body);

        return str_contains($body, 'rate limit')
            || str_contains($body, 'rate-limited')
            || str_contains($body, 'too many requests');
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
