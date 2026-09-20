<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blockchain\NetworkProvisioner;
use App\Support\Network;
use Illuminate\Console\Command;

class SyncNetworks extends Command
{
    protected $signature = 'app:sync-networks';

    protected $description = 'Create missing treasury wallet, gas policy, network settings and scan-state rows from the network registry';

    public function handle(NetworkProvisioner $provisioner): int
    {
        foreach (Network::enabledKeys() as $key) {
            foreach ($provisioner->provision($key) as $message) {
                str_starts_with($message, 'Skipping')
                    ? $this->warn($message)
                    : $this->info($message);
            }
        }

        return self::SUCCESS;
    }
}
