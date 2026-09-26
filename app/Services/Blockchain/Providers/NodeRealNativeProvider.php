<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Models\BlockchainScanState;
use App\Models\Deposit;
use App\Services\Blockchain\DepositExclusions;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use App\Support\Network;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class NodeRealNativeProvider implements BlockchainProvider
{
    // Free plan budget (~300 CUPS): keep nr_* calls at least 1 s apart,
    // plain gate RPCs 250 ms apart, shared across instances.
    private const NR_MIN_REQUEST_INTERVAL_US = 1_000_000;

    private const GATE_MIN_REQUEST_INTERVAL_US = 250_000;

    private const CHUNK_SIZE = 1000;

    private const MAX_COUNT = '0x3E8';

    private static float $lastGateCallAt = 0.0;

    private static float $lastNrCallAt = 0.0;

    private readonly Closure $sleeper;

    public function __construct(
        private readonly string $network,
        private readonly ?string $rpcUrl = null,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper === null
            ? fn (int $microseconds) => usleep($microseconds)
            : $sleeper(...);
    }

    public function fetchTransactions(array $addresses): array
    {
        if ($addresses === [] || $this->rpcUrl === null || $this->rpcUrl === '') {
            return [];
        }

        $current = (int) hexdec((string) $this->gateRpc('eth_blockNumber', []));
        $confirmationsRequired = max(0, Network::confirmations($this->network));
        $from = $this->fromBlock($current, $confirmationsRequired);
        $blockParam = '0x'.dechex($current);

        $treasuryAddresses = DepositExclusions::treasuryAddresses();
        $topupHashes = DepositExclusions::topupHashes();

        $transactions = [];
        $snapshots = [];

        foreach ($addresses as $address) {
            $snapshot = [
                'balance' => (string) $this->gateRpc('eth_getBalance', [$address, $blockParam]),
                'nonce' => (string) $this->gateRpc('eth_getTransactionCount', [$address, $blockParam]),
            ];
            $snapshots[$address] = $snapshot;

            if (! $this->isCandidate($address, $snapshot, $confirmationsRequired)) {
                continue;
            }

            $seenHashes = [];

            for ($chunkStart = $from; $chunkStart <= $current; $chunkStart += self::CHUNK_SIZE) {
                $chunkEnd = min($current, $chunkStart + self::CHUNK_SIZE - 1);
                $pageKey = '';

                do {
                    $params = [[
                        'category' => ['external'],
                        'addressType' => 'to',
                        'address' => $address,
                        'order' => 'asc',
                        'maxCount' => self::MAX_COUNT,
                        'fromBlock' => '0x'.dechex($chunkStart),
                        'toBlock' => '0x'.dechex($chunkEnd),
                    ]];

                    if ($pageKey !== '') {
                        $params[0]['pageKey'] = $pageKey;
                    }

                    $result = $this->nrRpc('nr_getTransactionByAddress', $params);

                    foreach ((array) ($result['transfers'] ?? []) as $transfer) {
                        if (! is_array($transfer) || $this->shouldSkip($transfer, $address, $treasuryAddresses, $topupHashes, $seenHashes)) {
                            continue;
                        }

                        $hash = (string) ($transfer['hash'] ?? '');
                        $seenHashes[$hash] = true;
                        $blockNum = (int) hexdec((string) ($transfer['blockNum'] ?? '0x0'));

                        $transactions[] = new BlockchainTransaction(
                            network: $this->network,
                            txHash: $hash,
                            toAddress: $address,
                            amount: bcdiv($this->hexToDec((string) ($transfer['value'] ?? '0x0')), bcpow('10', '18', 0), 8),
                            confirmations: max(0, $current - $blockNum + 1),
                        );
                    }

                    $pageKey = (string) ($result['pageKey'] ?? '');
                } while ($pageKey !== '');
            }
        }

        foreach ($snapshots as $address => $snapshot) {
            Cache::put($this->snapshotKey($address), $snapshot);
        }

        BlockchainScanState::updateOrCreate(
            ['network' => $this->network],
            ['last_scanned_block' => $current],
        );

        return $transactions;
    }

    private function fromBlock(int $current, int $confirmationsRequired): int
    {
        $lastScanned = BlockchainScanState::query()
            ->where('network', $this->network)
            ->value('last_scanned_block');

        if ($lastScanned === null) {
            return max(0, $current - self::CHUNK_SIZE + 1);
        }

        return max(0, (int) $lastScanned + 1 - max(0, $confirmationsRequired - 1));
    }

    /**
     * @param  array{balance: string, nonce: string}  $snapshot
     */
    private function isCandidate(string $address, array $snapshot, int $confirmationsRequired): bool
    {
        $cached = Cache::get($this->snapshotKey($address));

        if (! is_array($cached)
            || ($cached['balance'] ?? null) !== $snapshot['balance']
            || ($cached['nonce'] ?? null) !== $snapshot['nonce']) {
            return true;
        }

        return Deposit::withoutGlobalScope('owner')
            ->where('network', $this->network)
            ->where('status', 'pending')
            ->where('confirmation_count', '<', $confirmationsRequired)
            ->whereHas('depositAddress', fn ($query) => $query
                ->whereRaw('LOWER(address) = ?', [strtolower($address)]))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $transfer
     * @param  array<string, bool>  $treasuryAddresses
     * @param  array<string, bool>  $topupHashes
     * @param  array<string, bool>  $seenHashes
     */
    private function shouldSkip(array $transfer, string $address, array $treasuryAddresses, array $topupHashes, array $seenHashes): bool
    {
        $hash = (string) ($transfer['hash'] ?? '');
        $to = (string) ($transfer['to'] ?? '');
        $from = (string) ($transfer['from'] ?? '');
        $rawValue = (string) ($transfer['value'] ?? '0x0');

        if ($hash === '' || isset($seenHashes[$hash])) {
            return true;
        }

        if (strtolower($to) !== strtolower($address)) {
            return true;
        }

        if (isset($transfer['category']) && $transfer['category'] !== 'external') {
            return true;
        }

        if ((int) ($transfer['receiptsStatus'] ?? 0) !== 1) {
            return true;
        }

        if (bccomp($this->hexToDec($rawValue), '0', 0) <= 0) {
            return true;
        }

        if (isset($treasuryAddresses[strtolower($from)])) {
            return true;
        }

        return isset($topupHashes[strtolower($hash)]);
    }

    private function gateRpc(string $method, array $params): mixed
    {
        $this->throttle(self::$lastGateCallAt, self::GATE_MIN_REQUEST_INTERVAL_US);

        return $this->rpc($method, $params);
    }

    private function nrRpc(string $method, array $params): array
    {
        $this->throttle(self::$lastNrCallAt, self::NR_MIN_REQUEST_INTERVAL_US);

        $result = $this->rpc($method, $params);

        return is_array($result) ? $result : [];
    }

    private function throttle(float &$lastCallAt, int $minIntervalUs): void
    {
        $now = microtime(true);
        $elapsedUs = (int) (($now - $lastCallAt) * 1_000_000);
        $remaining = $minIntervalUs - $elapsedUs;

        if ($lastCallAt > 0.0 && $remaining > 0) {
            ($this->sleeper)($remaining);
        }

        $lastCallAt = microtime(true);
    }

    private function rpc(string $method, array $params): mixed
    {
        $response = Http::timeout(30)->post($this->rpcUrl, [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('NodeReal RPC returned an error: '.$response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new RuntimeException('NodeReal RPC error: '.json_encode($data['error']));
        }

        return $data['result'] ?? null;
    }

    private function hexToDec(string $hex): string
    {
        $hex = strtolower(trim($hex));

        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }

        if ($hex === '' || ! ctype_xdigit($hex)) {
            return '0';
        }

        $decimal = '0';

        foreach (str_split($hex === '' ? '0' : $hex) as $char) {
            $digit = strpos('0123456789abcdef', $char);
            $decimal = bcadd(bcmul($decimal, '16', 0), (string) $digit, 0);
        }

        return $decimal;
    }

    private function snapshotKey(string $address): string
    {
        return 'nodereal:'.$this->network.':snapshot:'.strtolower($address);
    }

    public function network(): string
    {
        return $this->network;
    }
}
