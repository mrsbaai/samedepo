<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BlockchainScanState;
use App\Models\PlatformSettings;
use App\Models\TreasuryWallet;
use App\Services\Blockchain\AddressGenerator;
use App\Services\Blockchain\GasTreasuryService;
use App\Support\Network;
use Illuminate\Console\Command;
use Throwable;

class SyncNetworks extends Command
{
    protected $signature = 'app:sync-networks';

    protected $description = 'Create missing treasury wallet, gas policy, network settings and scan-state rows from the network registry';

    public function handle(AddressGenerator $addresses, GasTreasuryService $gasTreasury): int
    {
        foreach (Network::enabledKeys() as $key) {
            PlatformSettings::networkSetting($key);
            BlockchainScanState::firstOrCreate(['network' => $key]);
            if (Network::isToken($key)) {
                $gasTreasury->policy($key);
            }

            if (TreasuryWallet::query()->where('network', $key)->exists()) {
                continue;
            }

            $sibling = TreasuryWallet::all()
                ->first(fn (TreasuryWallet $wallet): bool => Network::exists($wallet->network)
                    && Network::addressGroup($wallet->network) === Network::addressGroup($key));

            $index = $sibling?->derivation_index ?? 0;

            try {
                $address = $sibling?->address ?? $addresses->generate($key, $index);
            } catch (Throwable $e) {
                $this->warn("Skipping treasury wallet for {$key}: {$e->getMessage()}");

                continue;
            }

            TreasuryWallet::create([
                'network' => $key,
                'derivation_index' => $index,
                'address' => $address,
                'available_funds' => 0,
            ]);

            $this->info("Created treasury wallet for {$key}.");
        }

        return self::SUCCESS;
    }
}
