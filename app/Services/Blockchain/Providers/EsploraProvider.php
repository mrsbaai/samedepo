<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Providers;

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class EsploraProvider implements BlockchainProvider
{
    public function __construct(
        private readonly string $network,
        private readonly string $baseUrl = 'https://mempool.space/api',
    ) {}

    public function fetchTransactions(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $tipHeight = (int) $this->get('/blocks/tip/height')->body();
        $transactions = [];
        $lastAddress = array_key_last($addresses);
        $attempted = 0;
        $skipped = 0;
        $firstError = null;

        foreach ($addresses as $key => $address) {
            $attempted++;
            $txs = null;

            try {
                $txs = $this->get("/address/{$address}/txs")->json();
            } catch (Throwable $exception) {
                // Esplora has no cursor, so a skipped address is re-read on the
                // next scan. Only surface an error when the host looks dead
                // (first three all failed) or every fetch failed.
                $skipped++;
                $firstError ??= $exception;

                if ($skipped === $attempted && ($skipped === 3 || $attempted === count($addresses))) {
                    throw $exception;
                }
            }

            if (is_array($txs)) {
                foreach ($txs as $tx) {
                    if (! is_array($tx)) {
                        continue;
                    }

                    $vouts = $tx['vout'] ?? [];

                    if (! is_array($vouts)) {
                        continue;
                    }

                    $value = 0;

                    foreach ($vouts as $vout) {
                        if (is_array($vout) && strtolower((string) ($vout['scriptpubkey_address'] ?? '')) === strtolower($address)) {
                            $value += (int) ($vout['value'] ?? 0);
                        }
                    }

                    $hash = (string) ($tx['txid'] ?? '');

                    if ($hash === '' || $value <= 0) {
                        continue;
                    }

                    $confirmed = (bool) ($tx['status']['confirmed'] ?? false);
                    $height = (int) ($tx['status']['block_height'] ?? 0);
                    $confirmations = ($confirmed && $height > 0)
                        ? max(1, $tipHeight - $height + 1)
                        : 0;

                    $transactions[] = new BlockchainTransaction(
                        network: $this->network,
                        txHash: $hash,
                        toAddress: $address,
                        amount: bcdiv((string) $value, '100000000', 8),
                        confirmations: $confirmations,
                    );
                }
            }

            if ($key !== $lastAddress) {
                usleep(100_000);
            }
        }

        if ($skipped > 0) {
            Log::warning('Esplora address fetch skipped.', [
                'network' => $this->network,
                'skipped' => $skipped,
                'first_error' => $firstError?->getMessage(),
            ]);
        }

        return $transactions;
    }

    public function network(): string
    {
        return $this->network;
    }

    private function get(string $path): Response
    {
        $response = Http::timeout(10)->retry(3, 1000, throw: false)
            ->get(rtrim($this->baseUrl, '/').$path);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Esplora API returned an error: '.$response->body());
        }

        return $response;
    }
}
