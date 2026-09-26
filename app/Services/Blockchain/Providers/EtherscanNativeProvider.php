<?php

namespace App\Services\Blockchain\Providers;

use App\Models\BlockchainScanState;
use App\Services\Blockchain\DepositExclusions;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use App\Support\Network;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Polls Etherscan V2 `account&action=txlist` for native transfers to watched
 * deposit addresses (e.g. ETH on mainnet).
 *
 * Minimum-loss: the shared EVM address also receives our own gas top-ups, so
 * any transaction whose `from` is a treasury wallet address or whose hash is
 * a known `gas_topups.tx_hash` is excluded and can never become a customer
 * deposit.
 */
class EtherscanNativeProvider implements BlockchainProvider
{
    private const END_BLOCK = '99999999';

    // Etherscan free tier allows 3 requests per second — per API key, so the
    // throttle is shared across every chain instance using that key.
    private const MIN_REQUEST_INTERVAL_US = 400_000;

    private static float $lastRequestAt = 0.0;

    private readonly Closure $sleeper;

    public function __construct(
        private readonly string $network,
        private readonly string $apiKey,
        private readonly int $chainId = 1,
        private readonly string $baseUrl = 'https://api.etherscan.io/v2/api',
        ?callable $sleeper = null,
        private readonly bool $requiresApiKey = true,
    ) {
        $this->sleeper = $sleeper === null
            ? fn (int $microseconds) => usleep($microseconds)
            : $sleeper(...);
    }

    public function network(): string
    {
        return $this->network;
    }

    /**
     * @param  array<int, string>  $addresses
     * @return array<int, BlockchainTransaction>
     *
     * @throws ConnectionException on transport failure, InvalidArgumentException on API errors.
     */
    public function fetchTransactions(array $addresses): array
    {
        if ($addresses === [] || ($this->requiresApiKey && $this->apiKey === '')) {
            return [];
        }

        $confirmationsRequired = Network::confirmations($this->network);
        $state = BlockchainScanState::where('network', $this->network)->first();
        $lastBlock = $state !== null ? (int) $state->last_scanned_block : 0;
        $startBlock = max(0, $lastBlock - $confirmationsRequired + 1);
        $treasuryAddresses = DepositExclusions::treasuryAddresses();
        $topupHashes = DepositExclusions::topupHashes();
        $seenHashes = [];
        $transactions = [];
        $maxBlock = $lastBlock;

        foreach ($addresses as $address) {
            foreach ($this->txlist($address, $startBlock) as $tx) {
                if ($this->shouldSkip($tx, $address, $treasuryAddresses, $topupHashes, $seenHashes)) {
                    continue;
                }

                $seenHashes[$tx['hash']] = true;
                $transactions[] = new BlockchainTransaction(
                    txHash: $tx['hash'],
                    toAddress: $tx['to'],
                    amount: bcdiv($tx['value'], '1000000000000000000', 8),
                    confirmations: (int) $tx['confirmations'],
                    network: $this->network,
                );
                $maxBlock = max($maxBlock, (int) $tx['blockNumber']);
            }
        }

        BlockchainScanState::updateOrCreate(
            ['network' => $this->network],
            ['last_scanned_block' => $maxBlock],
        );

        return $transactions;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function txlist(string $address, int $startBlock): array
    {
        $data = $this->requestTxlist($address, $startBlock);

        // A rate-limited NOTOK gets exactly one retry after a 1 s pause.
        if (is_string($data['result'] ?? null) && str_contains(strtolower($data['result']), 'rate limit')) {
            ($this->sleeper)(1_000_000);
            $data = $this->requestTxlist($address, $startBlock);
        }

        if (is_string($data['result'] ?? null)) {
            // NOTOK payloads (e.g. "Max rate limit reached") must surface as
            // errors so the scanner backs off instead of reading them as empty.
            if (($data['message'] ?? '') === 'No transactions found') {
                return [];
            }

            throw new InvalidArgumentException(
                "Etherscan error for network {$this->network}: {$data['result']}"
            );
        }

        return $data['result'];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestTxlist(string $address, int $startBlock): array
    {
        $this->throttle();

        $query = [
            'chainid' => $this->chainId,
            'module' => 'account',
            'action' => 'txlist',
            'address' => $address,
            'startblock' => $startBlock,
            'endblock' => self::END_BLOCK,
            'sort' => 'asc',
        ];

        if ($this->requiresApiKey) {
            $query['apikey'] = $this->apiKey;
        }

        $response = Http::get($this->baseUrl, $query);

        if (! $response->successful()) {
            throw new InvalidArgumentException(
                "Etherscan request failed for network {$this->network}: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! array_key_exists('result', $data)) {
            throw new InvalidArgumentException(
                "Etherscan returned a malformed response for network {$this->network}"
            );
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $tx
     * @param  array<string, bool>  $treasuryAddresses
     * @param  array<string, bool>  $topupHashes
     * @param  array<string, bool>  $seenHashes
     */
    private function shouldSkip(array $tx, string $address, array $treasuryAddresses, array $topupHashes, array $seenHashes): bool
    {
        if (! isset($tx['hash'], $tx['to'], $tx['value'])) {
            return true;
        }

        if (isset($seenHashes[$tx['hash']])) {
            return true;
        }

        if (strcasecmp($tx['to'], $address) !== 0) {
            return true;
        }

        if (($tx['isError'] ?? '1') !== '0' || ($tx['txreceipt_status'] ?? '0') !== '1') {
            return true;
        }

        if (bccomp($tx['value'], '0', 0) <= 0) {
            return true;
        }

        $from = strtolower($tx['from'] ?? '');
        if ($from !== '' && isset($treasuryAddresses[$from])) {
            return true;
        }

        return isset($topupHashes[strtolower($tx['hash'])]);
    }

    private function throttle(): void
    {
        if (self::$lastRequestAt > 0.0) {
            $elapsedUs = (int) ((microtime(true) - self::$lastRequestAt) * 1_000_000);
            $waitUs = self::MIN_REQUEST_INTERVAL_US - $elapsedUs;

            if ($waitUs > 0) {
                ($this->sleeper)($waitUs);
            }
        }

        self::$lastRequestAt = microtime(true);
    }
}
