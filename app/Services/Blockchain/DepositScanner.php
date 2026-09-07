<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Events\DepositPending;
use App\Models\BlockchainScanState;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DepositScanner
{
    /** @var array<string, BlockchainProvider> */
    private readonly array $providers;

    public function __construct(array $providers)
    {
        $indexed = [];

        foreach ($providers as $provider) {
            $indexed[$provider->network()] = $provider;
        }

        $this->providers = $indexed;
    }

    public function scan(): void
    {
        $addressesByNetwork = $this->watchedAddressesByNetwork();

        foreach ($addressesByNetwork as $network => $addresses) {
            $provider = $this->providers[$network] ?? null;

            if ($provider === null) {
                continue;
            }

            $state = BlockchainScanState::query()->firstOrCreate(['network' => $network]);

            if ($state->cooldown_until?->isFuture()) {
                Log::debug('Blockchain deposit scan skipped during provider cooldown.', [
                    'network' => $network,
                    'cooldown_until' => $state->cooldown_until->toIso8601String(),
                    'consecutive_failures' => $state->consecutive_failures,
                ]);

                continue;
            }

            if ($state->next_scan_at?->isFuture()) {
                continue;
            }

            try {
                $this->processNetwork($network, $provider, $addresses);
                $state->update([
                    'next_scan_at' => now()->addMinutes((int) config("blockchain.scan_intervals.{$network}", 5)),
                    'cooldown_until' => null,
                    'consecutive_failures' => 0,
                ]);
            } catch (Throwable $exception) {
                $failures = $state->consecutive_failures + 1;
                $baseMinutes = (int) config('blockchain.provider_backoff.base_minutes', 2);
                $maxMinutes = (int) config('blockchain.provider_backoff.max_minutes', 60);
                $cooldownMinutes = min($maxMinutes, $baseMinutes * (2 ** min($failures - 1, 10)));
                $state->update([
                    'cooldown_until' => now()->addMinutes($cooldownMinutes),
                    'consecutive_failures' => $failures,
                ]);

                Log::error('Blockchain deposit scan failed.', [
                    'network' => $network,
                    'provider' => $provider::class,
                    'consecutive_failures' => $failures,
                    'cooldown_minutes' => $cooldownMinutes,
                    'exception' => $exception,
                ]);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function watchedAddressesByNetwork(): array
    {
        return DepositAddress::query()
            ->get(['id', 'network', 'address', 'customer_id'])
            ->groupBy('network')
            ->map(fn (Collection $items) => $items->keyBy('id'))
            ->map(fn (Collection $items) => $items->map(fn (DepositAddress $address) => $address->address)->all())
            ->all();
    }

    /**
     * @param  array<int, string>  $addresses
     */
    private function processNetwork(string $network, BlockchainProvider $provider, array $addresses): void
    {
        $addressRecords = DepositAddress::query()
            ->where('network', $network)
            ->get()
            ->keyBy(fn (DepositAddress $address) => strtolower($address->address));

        foreach ($provider->fetchTransactions($addresses) as $transaction) {
            $addressRecord = $addressRecords->get(strtolower($transaction->toAddress));

            if ($addressRecord === null) {
                continue;
            }

            DB::transaction(function () use ($addressRecord, $network, $transaction): void {
                $deposit = Deposit::firstOrCreate(
                    [
                        'deposit_address_id' => $addressRecord->id,
                        'tx_hash' => $transaction->txHash,
                    ],
                    [
                        'customer_id' => $addressRecord->customer_id,
                        'user_id' => $addressRecord->customer->user_id,
                        'network' => $network,
                        'gross_amount' => $transaction->amount,
                        'confirmation_count' => $transaction->confirmations,
                        'status' => 'pending',
                        'detected_at' => now(),
                    ]
                );

                if ($deposit->status !== 'credited') {
                    $deposit->update([
                        'confirmation_count' => $transaction->confirmations,
                        'status' => $deposit->status === 'ignored' ? 'ignored' : 'pending',
                    ]);
                }

                if ($deposit->wasRecentlyCreated) {
                    event(new DepositPending($deposit->fresh()));
                }
            });
        }
    }
}
