<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Fees;

use App\Models\Withdrawal;

interface WithdrawalFeeEstimator
{
    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string;
}
