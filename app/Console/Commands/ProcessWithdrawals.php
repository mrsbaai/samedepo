<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blockchain\WithdrawalProcessor;
use Illuminate\Console\Command;

class ProcessWithdrawals extends Command
{
    protected $signature = 'app:process-withdrawals';

    protected $description = 'Send instant and approved blockchain withdrawals';

    public function handle(WithdrawalProcessor $processor): int
    {
        $processor->process();

        return self::SUCCESS;
    }
}
