<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\Withdrawal;

class NullBlockchainBroadcaster implements BlockchainBroadcaster
{
    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return null;
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        return null;
    }

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        return null;
    }

    public function getNativeBalance(string $network, int $index): ?string
    {
        return null;
    }

    public function getTronResource(int $index): ?array
    {
        return null;
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        return null;
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return null;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return null;
    }
}
