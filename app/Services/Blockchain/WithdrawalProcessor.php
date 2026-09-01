<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\LedgerEntry;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;

class WithdrawalProcessor
{
    public function __construct(
        private readonly BlockchainBroadcaster $broadcaster,
        private ?GasTreasuryService $gasTreasury = null,
        private ?FeeConverter $feeConverter = null,
    ) {
        $this->gasTreasury ??= new GasTreasuryService($this->broadcaster);
        $this->feeConverter ??= new FeeConverter;
    }

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

        $wallet = TreasuryWallet::query()
            ->where('network', $withdrawal->network)
            ->lockForUpdate()
            ->first();

        if ($wallet === null || bccomp((string) $wallet->available_funds, (string) $withdrawal->gross_amount, 8) < 0) {
            return;
        }

        $estimatedFeeNative = $this->broadcaster->estimateWithdrawalFee($withdrawal);

        if ($estimatedFeeNative === null) {
            return;
        }

        $networkFeeNative = $this->feeConverter->bufferedNativeFee($estimatedFeeNative);
        $totalFee = $this->calculateTotalFee($withdrawal, $networkFeeNative);

        if ($totalFee === null) {
            return;
        }

        $amountSent = bccomp((string) $withdrawal->gross_amount, $totalFee, 8) >= 0
            ? bcsub((string) $withdrawal->gross_amount, $totalFee, 8)
            : '0.00000000';

        $withdrawal->update([
            'network_fee' => $totalFee,
            'network_fee_native' => $networkFeeNative,
            'amount_sent' => $amountSent,
        ]);

        $isToken = in_array($withdrawal->network, ['usdt_erc20', 'usdt_trc20'], true);
        if ($isToken && ! $this->gasTreasury->ensureGasForWithdrawal($withdrawal)) {
            return;
        }

        $txHash = $this->broadcaster->broadcastWithdrawal($withdrawal);

        if ($txHash === null) {
            return;
        }

        $withdrawal->update([
            'status' => 'sent',
            'tx_hash' => $txHash,
            'sent_at' => now(),
        ]);

        $treasurySpend = $withdrawal->network === 'bitcoin'
            ? bcadd((string) $amountSent, (string) $withdrawal->network_fee_native, 8)
            : $amountSent;
        $wallet->available_funds = bcsub((string) $wallet->available_funds, $treasurySpend, 8);
        $wallet->save();

        LedgerEntry::create([
            'user_id' => $withdrawal->user_id,
            'network' => $withdrawal->network,
            'amount' => '-'.$totalFee,
            'reason' => 'network_fee',
            'withdrawal_id' => $withdrawal->id,
        ]);

        $this->markSweepsRecovered($withdrawal);
    }

    private function calculateTotalFee(Withdrawal $withdrawal, string $networkFeeNative): ?string
    {
        $networkFee = $this->feeConverter->toNetworkUnits($withdrawal->network, $networkFeeNative);
        $recovery = $this->feeConverter->toNetworkUnits(
            $withdrawal->network,
            $this->feeConverter->unrecoveredSweepGasNative($withdrawal->user_id, $withdrawal->network),
        );

        return $networkFee === null || $recovery === null ? null : bcadd($networkFee, $recovery, 8);
    }

    private function markSweepsRecovered(Withdrawal $withdrawal): void
    {
        TreasurySweep::query()
            ->where('network', $withdrawal->network)
            ->where('status', 'confirmed')
            ->whereNull('fee_recovered_at')
            ->where(function ($query) use ($withdrawal): void {
                $query->whereExists(function ($sub) use ($withdrawal): void {
                    $sub->selectRaw('1')
                        ->from('deposits')
                        ->whereColumn('deposits.id', 'treasury_sweeps.deposit_id')
                        ->where('deposits.user_id', $withdrawal->user_id);
                })->orWhereExists(function ($sub) use ($withdrawal): void {
                    $sub->selectRaw('1')
                        ->from('deposit_addresses')
                        ->join('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
                        ->whereColumn('deposit_addresses.id', 'treasury_sweeps.deposit_address_id')
                        ->where('customers.user_id', $withdrawal->user_id);
                });
            })
            ->update([
                'fee_recovered_at' => now(),
                'recovered_withdrawal_id' => $withdrawal->id,
            ]);
    }
}
