<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\GasExpense;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWallet;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;

class TreasuryPayoutService
{
    public function __construct(private readonly BlockchainBroadcaster $broadcaster)
    {
    }

    public function send(TreasuryPayout $payout): bool
    {
        if ($payout->status !== 'pending') {
            return false;
        }

        $wallet = TreasuryWallet::query()
            ->where('network', $payout->network)
            ->lockForUpdate()
            ->first();

        if ($wallet === null) {
            $payout->update(['status' => 'failed', 'error_message' => 'No treasury wallet for network']);

            return false;
        }

        if (bccomp((string) $payout->amount, (string) $wallet->available_funds, 8) > 0) {
            $payout->update(['status' => 'failed', 'error_message' => 'Amount exceeds available funds']);

            return false;
        }

        $estimatedFeeNative = $this->broadcaster->estimateFee(
            $payout->network,
            $payout->network !== 'bitcoin',
        );

        if ($estimatedFeeNative === null) {
            $payout->update(['status' => 'failed', 'error_message' => 'Fee estimate unavailable']);

            return false;
        }

        if ($payout->network === 'bitcoin'
            && bccomp(bcadd((string) $payout->amount, $estimatedFeeNative, 8), (string) $wallet->available_funds, 8) > 0) {
            $payout->update(['status' => 'failed', 'error_message' => 'Amount plus network fee exceeds available funds']);

            return false;
        }

        $payout->network_fee = $estimatedFeeNative;

        $txHash = $this->broadcaster->broadcastPayout($payout);

        if ($txHash === null) {
            $payout->update([
                'status' => 'failed',
                'error_message' => 'Broadcast failed',
                'network_fee' => $estimatedFeeNative,
            ]);

            return false;
        }

        DB::transaction(function () use ($payout, $wallet, $txHash, $estimatedFeeNative): void {
            $treasurySpend = $payout->network === 'bitcoin'
                ? bcadd((string) $payout->amount, $estimatedFeeNative, 8)
                : (string) $payout->amount;
            $wallet->available_funds = bcsub((string) $wallet->available_funds, $treasurySpend, 8);
            $wallet->save();

            $payout->update([
                'status' => 'sent',
                'tx_hash' => $txHash,
                'network_fee' => $estimatedFeeNative,
                'sent_at' => now(),
            ]);
        });

        return true;
    }

    public function poll(): void
    {
        TreasuryPayout::query()
            ->where('status', 'sent')
            ->chunkById(100, function ($payouts): void {
                foreach ($payouts as $payout) {
                    $receipt = $this->broadcaster->getTransactionReceipt(
                        $payout->network,
                        $payout->tx_hash,
                    );

                    if ($receipt === null) {
                        continue;
                    }

                    if ($receipt['status'] === 'confirmed') {
                        $payout->update([
                            'status' => 'confirmed',
                            'confirmed_at' => now(),
                        ]);

                        GasExpense::create([
                            'network' => $payout->network,
                            'tx_hash' => $payout->tx_hash,
                            'amount' => $receipt['fee'] ?? '0.00000000',
                            'expensable_type' => TreasuryPayout::class,
                            'expensable_id' => $payout->id,
                        ]);

                        continue;
                    }

                    if ($receipt['status'] === 'failed') {
                        $payout->update([
                            'status' => 'failed',
                            'error_message' => 'Receipt failed',
                        ]);
                    }
                }
            });
    }
}
