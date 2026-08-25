<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Broadcasters;

use App\Models\TreasurySweep;
use App\Models\Withdrawal;

interface BlockchainBroadcaster
{
    /**
     * Broadcast a sweep transaction for the given sweep.
     *
     * @return string|null The transaction hash if broadcast, or null if not sent.
     */
    public function broadcastSweep(TreasurySweep $sweep): ?string;

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string;
}
