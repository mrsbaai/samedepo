<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Blockchain\CustomerAddressProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillDepositAddresses extends Command
{
    protected $signature = 'app:backfill-deposit-addresses';

    protected $description = 'Create deposit addresses for every enabled network that existing customers are missing';

    public function handle(CustomerAddressProvisioner $provisioner): int
    {
        $total = 0;
        $customers = 0;

        Customer::query()->with('depositAddresses')->orderBy('id')->chunkById(100, function ($chunk) use ($provisioner, &$total, &$customers) {
            foreach ($chunk as $c) {
                $customers++;
                $n = DB::transaction(fn () => $provisioner->provision($c));

                if ($n > 0) {
                    $this->line("customer {$c->customer_reference}: +{$n}");
                }

                $total += $n;
            }
        });

        $this->info("Created {$total} addresses for {$customers} customers.");

        return self::SUCCESS;
    }
}
