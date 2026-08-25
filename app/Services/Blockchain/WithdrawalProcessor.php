<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;

class WithdrawalProcessor
{
    public function __construct(private readonly BlockchainBroadcaster $broadcaster) {}

    public function process(): void
    {
        Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->where(function ($query): void {
                $query->where(fn ($query) => $query->where('mode', 'instant')->where('status', 'pending'))
                    ->orWhere('status', 'approved');
            })
            ->chunkById(100, function ($withdrawals): void {
                foreach ($withdrawals as $withdrawal) {
                    DB::transaction(fn () => $this->send($withdrawal->id));
                }
            });
    }

    private function send(int $withdrawalId): void
    {
        $withdrawal = Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->lockForUpdate()
            ->find($withdrawalId);

        if ($withdrawal === null || ($withdrawal->status !== 'approved' && ! ($withdrawal->mode === 'instant' && $withdrawal->status === 'pending'))) {
            return;
        }

        $txHash = $this->broadcaster->broadcastWithdrawal($withdrawal);

        if ($txHash === null) {
            return;
        }

        $withdrawal->update([
            'status' => 'sent',
            'amount_sent' => $withdrawal->amount_sent ?? $withdrawal->gross_amount,
            'tx_hash' => $txHash,
            'sent_at' => now(),
        ]);
    }
}
