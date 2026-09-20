<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\BlockchainScanState;
use App\Models\PlatformSettings;
use App\Models\TreasuryWallet;
use App\Support\Network;
use Throwable;

/**
 * Creates the DB rows a registry network needs before it can operate:
 * network_settings, scan state, gas policy (tokens) and a treasury wallet
 * (sibling address-group wallets share the derivation index and address).
 */
class NetworkProvisioner
{
    public function __construct(
        private readonly AddressGenerator $addresses,
        private readonly GasTreasuryService $gasTreasury,
    ) {}

    /**
     * Provision a single registry network. Idempotent — disabling and
     * re-enabling never deletes or duplicates rows.
     *
     * @return array<int, string> info/warning lines for the caller to surface
     */
    public function provision(string $key): array
    {
        $messages = [];

        PlatformSettings::networkSetting($key);
        BlockchainScanState::firstOrCreate(['network' => $key]);

        if (Network::isToken($key)) {
            $this->gasTreasury->policy($key);
        }

        if (TreasuryWallet::query()->where('network', $key)->exists()) {
            return $messages;
        }

        $sibling = TreasuryWallet::all()
            ->first(fn (TreasuryWallet $wallet): bool => Network::exists($wallet->network)
                && Network::addressGroup($wallet->network) === Network::addressGroup($key));

        $index = $sibling?->derivation_index ?? 0;

        try {
            $address = $sibling?->address ?? $this->addresses->generate($key, $index);
        } catch (Throwable $e) {
            $messages[] = "Skipping treasury wallet for {$key}: {$e->getMessage()}";

            return $messages;
        }

        TreasuryWallet::create([
            'network' => $key,
            'derivation_index' => $index,
            'address' => $address,
            'available_funds' => 0,
        ]);

        $messages[] = "Created treasury wallet for {$key}.";

        return $messages;
    }
}
