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
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Native-coin deposit detection with plain JSON-RPC only (no indexer API):
 * gate each address on its latest balance, bisect historical balances on an
 * archive-capable RPC to find the blocks where it changed, then read those
 * blocks for incoming transfers.
 */
class EvmNativeBisectProvider implements BlockchainProvider
{
    // ~50 min of BSC blocks — inside the ~5k-block state window dRPC keeps.
    // Older gaps are skipped (and logged) rather than bisected.
    private const MAX_RANGE = 4_000;

    // Stay behind the gate RPC's tip so the archive RPC has the block too.
    private const TIP_MARGIN = 3;

    private const MIN_REQUEST_INTERVAL_US = 100_000;

    private static float $lastCallAt = 0.0;

    private readonly Closure $sleeper;

    public function __construct(
        private readonly string $network,
        private readonly ?string $rpcUrl = null,
        private readonly ?string $gateRpcUrl = null,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper === null
            ? fn (int $microseconds) => usleep($microseconds)
            : $sleeper(...);
    }

    public function network(): string
    {
        return $this->network;
    }

    public function fetchTransactions(array $addresses): array
    {
        if ($addresses === [] || $this->rpcUrl === null || $this->rpcUrl === '') {
            return [];
        }

        $gate = $this->gateRpcUrl ?: $this->rpcUrl;
        $current = $this->hexInt($this->rpc($gate, 'eth_blockNumber', [])) - self::TIP_MARGIN;
        $required = max(0, Network::confirmations($this->network));
        $lastScanned = BlockchainScanState::query()->where('network', $this->network)->value('last_scanned_block');
        $from = $lastScanned === null ? $current : max((int) $lastScanned, $current - self::MAX_RANGE);

        if ($lastScanned !== null && (int) $lastScanned < $current - self::MAX_RANGE) {
            Log::warning('Native bisect scan gap exceeds the maximum range; older blocks skipped.', [
                'network' => $this->network,
                'last_scanned_block' => (int) $lastScanned,
                'from' => $from,
            ]);
        }

        $treasury = DepositExclusions::treasuryAddresses();
        $topups = DepositExclusions::topupHashes();
        $transactions = [];
        $snapshots = [];
        $seen = [];

        foreach ($addresses as $address) {
            $balance = $this->hexDec($this->rpc($gate, 'eth_getBalance', [$address, $this->hex($current)]));
            $snapshots[$address] = $balance;

            if (Cache::get($this->snapshotKey($address)) === $balance || $from >= $current) {
                continue;
            }

            $blocks = [];
            $this->changeBlocks($address, $from, $this->balanceAt($address, $from), $current, $balance, $blocks);

            foreach ($blocks as $blockNumber) {
                $block = (array) $this->rpc($this->rpcUrl, 'eth_getBlockByNumber', [$this->hex($blockNumber), true]);

                foreach ((array) ($block['transactions'] ?? []) as $tx) {
                    $hash = strtolower((string) ($tx['hash'] ?? ''));

                    if ($hash === '' || isset($seen[$hash]) || isset($topups[$hash])
                        || strtolower((string) ($tx['to'] ?? '')) !== strtolower($address)
                        || isset($treasury[strtolower((string) ($tx['from'] ?? ''))])
                        || bccomp($this->hexDec((string) ($tx['value'] ?? '0x0')), '0', 0) <= 0
                        || ! $this->succeeded($hash)) {
                        continue;
                    }

                    $seen[$hash] = true;
                    $transactions[] = $this->transaction($hash, $address, (string) $tx['value'], $current - $blockNumber + 1);
                }
            }
        }

        foreach ($this->pendingDeposits($addresses, $required) as $deposit) {
            $hash = strtolower((string) $deposit->tx_hash);

            if (isset($seen[$hash])) {
                continue;
            }

            $receipt = $this->rpc($this->rpcUrl, 'eth_getTransactionReceipt', [$deposit->tx_hash]);

            if (! is_array($receipt) || ($receipt['status'] ?? null) !== '0x1' || ! isset($receipt['blockNumber'])) {
                continue;
            }

            $transactions[] = new BlockchainTransaction(
                network: $this->network,
                txHash: (string) $deposit->tx_hash,
                toAddress: (string) $deposit->depositAddress->address,
                amount: (string) $deposit->gross_amount,
                confirmations: max(0, $current - $this->hexInt($receipt['blockNumber']) + 1),
            );
        }

        foreach ($snapshots as $address => $balance) {
            Cache::put($this->snapshotKey($address), $balance);
        }

        BlockchainScanState::updateOrCreate(['network' => $this->network], ['last_scanned_block' => $current]);

        return $transactions;
    }

    /**
     * @param  array<int, int>  $blocks
     */
    private function changeBlocks(string $address, int $lo, string $balanceLo, int $hi, string $balanceHi, array &$blocks): void
    {
        if ($balanceLo === $balanceHi) {
            return;
        }

        if ($hi - $lo <= 1) {
            $blocks[] = $hi;

            return;
        }

        $mid = intdiv($lo + $hi, 2);
        $balanceMid = $this->balanceAt($address, $mid);
        $this->changeBlocks($address, $lo, $balanceLo, $mid, $balanceMid, $blocks);
        $this->changeBlocks($address, $mid, $balanceMid, $hi, $balanceHi, $blocks);
    }

    private function balanceAt(string $address, int $block): string
    {
        return $this->hexDec($this->rpc($this->rpcUrl, 'eth_getBalance', [$address, $this->hex($block)]));
    }

    private function succeeded(string $hash): bool
    {
        $receipt = $this->rpc($this->rpcUrl, 'eth_getTransactionReceipt', [$hash]);

        return is_array($receipt) && ($receipt['status'] ?? null) === '0x1';
    }

    /**
     * @param  array<int, string>  $addresses
     * @return iterable<Deposit>
     */
    private function pendingDeposits(array $addresses, int $required): iterable
    {
        $watched = array_map('strtolower', $addresses);

        return Deposit::withoutGlobalScope('owner')
            ->with('depositAddress')
            ->where('network', $this->network)
            ->where('status', 'pending')
            ->where('confirmation_count', '<', $required)
            ->get()
            ->filter(fn (Deposit $deposit) => in_array(strtolower((string) $deposit->depositAddress?->address), $watched, true));
    }

    private function transaction(string $hash, string $address, string $valueHex, int $confirmations): BlockchainTransaction
    {
        return new BlockchainTransaction(
            network: $this->network,
            txHash: $hash,
            toAddress: $address,
            amount: bcdiv($this->hexDec($valueHex), bcpow('10', '18', 0), 8),
            confirmations: max(0, $confirmations),
        );
    }

    private function rpc(string $url, string $method, array $params): mixed
    {
        $elapsedUs = (int) ((microtime(true) - self::$lastCallAt) * 1_000_000);

        if (self::$lastCallAt > 0.0 && $elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            ($this->sleeper)(self::MIN_REQUEST_INTERVAL_US - $elapsedUs);
        }

        self::$lastCallAt = microtime(true);

        $response = Http::timeout(30)->post($url, ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1]);
        $data = $response->json();

        if (! $response->successful() || ! is_array($data) || isset($data['error'])) {
            throw new RuntimeException("{$method} failed: ".mb_substr($response->body(), 0, 300));
        }

        return $data['result'] ?? null;
    }

    private function hex(int $value): string
    {
        return '0x'.dechex($value);
    }

    private function hexInt(mixed $hex): int
    {
        return (int) hexdec(substr((string) $hex, 2));
    }

    private function hexDec(mixed $hex): string
    {
        $hex = strtolower((string) $hex);
        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;

        if ($hex === '' || ! ctype_xdigit($hex)) {
            return '0';
        }

        $decimal = '0';

        foreach (str_split($hex) as $char) {
            $decimal = bcadd(bcmul($decimal, '16', 0), (string) hexdec($char), 0);
        }

        return $decimal;
    }

    private function snapshotKey(string $address): string
    {
        return 'native-bisect:'.$this->network.':balance:'.strtolower($address);
    }
}
