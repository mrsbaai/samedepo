<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Deposit;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;

class TreasurySweepService
{
    public function __construct(private readonly BlockchainBroadcaster $broadcaster) {}

    public function sweep(): void
    {
        Deposit::query()
            ->where('status', 'credited')
            ->whereNull('swept_at')
            ->chunkById(100, function ($deposits): void {
                foreach ($deposits as $deposit) {
                    DB::transaction(function () use ($deposit): void {
                        $this->sweepDeposit($deposit);
                    });
                }
            });
    }

    private function sweepDeposit(Deposit $deposit): void
    {
        $wallet = TreasuryWallet::query()
            ->where('network', $deposit->network)
            ->lockForUpdate()
            ->first();

        if ($wallet === null) {
            return;
        }

        $sweep = TreasurySweep::query()->firstOrCreate(
            ['deposit_id' => $deposit->id],
            [
                'network' => $deposit->network,
                'amount' => $deposit->gross_amount,
                'status' => 'pending',
            ]
        );

        if ($sweep->status !== 'pending') {
            return;
        }

        $txHash = $this->broadcaster->broadcastSweep($sweep);

        if ($txHash === null) {
            return;
        }

        $wallet->available_funds = bcadd((string) $wallet->available_funds, (string) $sweep->amount, 8);
        $wallet->save();

        $sweep->update([
            'tx_hash' => $txHash,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $deposit->update(['swept_at' => now()]);
    }
}
