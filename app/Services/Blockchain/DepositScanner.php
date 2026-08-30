<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use Illuminate\Support\Collection;

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

            $this->processNetwork($network, $provider, $addresses);
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
        $confirmationRequirement = (int) config("blockchain.confirmations.{$network}", 0);
        $addressRecords = DepositAddress::query()
            ->where('network', $network)
            ->get()
            ->keyBy(fn (DepositAddress $address) => strtolower($address->address));

        foreach ($provider->fetchTransactions($addresses) as $transaction) {
            $addressRecord = $addressRecords->get(strtolower($transaction->toAddress));

            if ($addressRecord === null) {
                continue;
            }

            $status = $transaction->confirmations >= $confirmationRequirement ? 'pending' : 'detected';

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
                    'status' => $status,
                    'detected_at' => now(),
                ]
            );

            if (! in_array($deposit->status, ['credited', 'ignored'], true)) {
                $deposit->update([
                    'confirmation_count' => $transaction->confirmations,
                    'status' => $status,
                ]);
            }
        }
    }
}
