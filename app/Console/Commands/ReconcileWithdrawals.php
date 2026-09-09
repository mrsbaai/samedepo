<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blockchain\WithdrawalProcessor;
use Illuminate\Console\Command;

class ReconcileWithdrawals extends Command
{
    protected $signature = 'app:reconcile-withdrawals';

    protected $description = 'Record actual withdrawal gas from chain receipts and settle variances';

    public function handle(WithdrawalProcessor $processor): int
    {
        $processor->reconcile();

        return self::SUCCESS;
    }
}
