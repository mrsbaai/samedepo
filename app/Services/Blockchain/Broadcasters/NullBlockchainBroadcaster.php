<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

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
}
