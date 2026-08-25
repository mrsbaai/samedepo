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

        $fee = $this->broadcaster->estimateWithdrawalFee($withdrawal);

        if ($fee === null) {
            return;
        }

        $tokenNetworks = ['usdt_erc20', 'usdt_trc20'];
        if (in_array($withdrawal->network, $tokenNetworks, true)) {
            // For token networks the fee is paid in the native gas asset (ETH/TRX),
            // not in the token itself, so the full gross token amount is sent.
            $amountSent = (string) $withdrawal->gross_amount;
        } else {
            $amountSent = bccomp((string) $withdrawal->gross_amount, $fee, 8) >= 0
                ? bcsub((string) $withdrawal->gross_amount, $fee, 8)
                : '0.00000000';
        }

        $withdrawal->update([
            'network_fee' => $fee,
            'amount_sent' => $amountSent,
        ]);

        $txHash = $this->broadcaster->broadcastWithdrawal($withdrawal);

        if ($txHash !== null) {
            $withdrawal->update([
                'status' => 'sent',
                'tx_hash' => $txHash,
                'sent_at' => now(),
            ]);
        }
    }
}
