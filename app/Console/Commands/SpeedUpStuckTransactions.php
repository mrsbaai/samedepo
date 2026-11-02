<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blockchain\StuckTransactionService;
use Illuminate\Console\Command;

class SpeedUpStuckTransactions extends Command
{
    protected $signature = 'app:speed-up-stuck-transactions';

    protected $description = 'Re-sign stuck EVM broadcasts (sweeps, withdrawals, top-ups, payouts) at a higher gas price';

    public function handle(StuckTransactionService $service): int
    {
        $service->run();

        return self::SUCCESS;
    }
}
