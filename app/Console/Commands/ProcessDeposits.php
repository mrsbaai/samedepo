<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blockchain\DepositCreditor;
use App\Services\Blockchain\DepositScanner;
use Illuminate\Console\Command;

class ProcessDeposits extends Command
{
    protected $signature = 'app:process-deposits';

    protected $description = 'Detect, track confirmations, and credit blockchain deposits';

    public function handle(DepositScanner $scanner, DepositCreditor $creditor): int
    {
        $scanner->scan();
        $creditor->credit();

        return self::SUCCESS;
    }
}
