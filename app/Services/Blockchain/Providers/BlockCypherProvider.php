<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

class BlockCypherProvider implements BlockchainProvider
{
    public function __construct(
        private readonly string $network,
        private readonly string $baseUrl,
        private readonly ?string $token = null,
    ) {}

    public function fetchTransactions(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $total = count($addresses);
        $offset = ((int) Cache::get($this->offsetKey(), 0)) % $total;
        // The scanner passes id-keyed arrays; positional logic needs 0-based keys.
        $ordered = array_values($addresses);

        if ($offset !== 0) {
            $ordered = array_merge(array_slice($ordered, $offset), array_slice($ordered, 0, $offset));
        }

        $transactions = [];
        $scanned = 0;
        $skipped = 0;
        $firstError = null;
        $rateLimited = false;

        foreach ($ordered as $index => $address) {
            $response = null;
            $error = null;

            try {
                $response = $this->get($address);
            } catch (Throwable $exception) {
                $error = $exception;
            }

            if ($response !== null && $response->status() === 429) {
                if ($index === 0) {
                    throw new InvalidArgumentException('BlockCypher API returned an error: '.$response->body());
                }

                $rateLimited = true;
                break;
            }

            if ($response !== null && $response->successful()) {
                foreach ($this->parseTransactions($response, $address) as $transaction) {
                    $transactions[] = $transaction;
                }

                $scanned++;
            } else {
                $error ??= new InvalidArgumentException('BlockCypher API returned an error: '.$response->body());

                if ($index === 0) {
                    throw $error;
                }

                $skipped++;
                $firstError ??= $error;
            }

            if ($index < $total - 1) {
                Sleep::for(350)->milliseconds();
            }
        }

        if ($rateLimited) {
            Log::warning('BlockCypher rate limit; partial scan.', [
                'network' => $this->network,
                'scanned' => $scanned,
                'total' => $total,
            ]);
        }

        if ($skipped > 0) {
            Log::warning('BlockCypher address fetch skipped.', [
                'network' => $this->network,
                'skipped' => $skipped,
                'first_error' => $firstError?->getMessage(),
            ]);
        }

        Cache::put($this->offsetKey(), ($offset + $scanned) % $total);

        return $transactions;
    }

    private function get(string $address): Response
    {
        $query = ['limit' => 50];

        if ($this->token !== null && $this->token !== '') {
            $query['token'] = $this->token;
        }

        return Http::timeout(10)->get(
            rtrim($this->baseUrl, '/')."/addrs/{$address}/full",
            $query,
        );
    }

    /**
     * @return array<int, BlockchainTransaction>
     */
    private function parseTransactions(Response $response, string $address): array
    {
        $transactions = [];

        foreach ((array) $response->json('txs', []) as $tx) {
            if (! is_array($tx)) {
                continue;
            }

            $value = 0;

            foreach ((array) ($tx['outputs'] ?? []) as $output) {
                if (is_array($output) && in_array($address, (array) ($output['addresses'] ?? []), true)) {
                    $value += (int) ($output['value'] ?? 0);
                }
            }

            $hash = (string) ($tx['hash'] ?? '');

            if ($hash === '' || $value <= 0) {
                continue;
            }

            $transactions[] = new BlockchainTransaction(
                network: $this->network,
                txHash: $hash,
                toAddress: $address,
                amount: bcdiv((string) $value, '100000000', 8),
                confirmations: (int) ($tx['confirmations'] ?? 0),
            );
        }

        return $transactions;
    }

    private function offsetKey(): string
    {
        return 'blockcypher:'.$this->network.':offset';
    }

    public function network(): string
    {
        return $this->network;
    }
}
