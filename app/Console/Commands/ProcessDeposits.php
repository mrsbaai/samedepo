<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blockchain\DepositScanner;
use Illuminate\Console\Command;

class ProcessDeposits extends Command
{
    protected $signature = 'app:process-deposits';

    protected $description = 'Detect and track confirmations for blockchain deposits';

    public function handle(DepositScanner $scanner): int
    {
        $scanner->scan();

        return self::SUCCESS;
    }
}
