<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blockchain\DepositCreditor;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\TreasurySweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDeposits extends Command
{
    protected $signature = 'app:process-deposits';

    protected $description = 'Detect, track confirmations, and credit blockchain deposits';

    public function handle(DepositScanner $scanner, DepositCreditor $creditor, TreasurySweepService $sweeper): int
    {
        Log::info('Deposit processing started.');

        $scanner->scan();
        $creditor->credit();
        $sweeper->sweep();

        Log::info('Deposit processing completed.');

        return self::SUCCESS;
    }
}
