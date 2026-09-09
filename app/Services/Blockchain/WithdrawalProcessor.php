<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Balance;
use App\Models\GasExpense;
use App\Models\LedgerEntry;
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
        $totalFee = $this->feeConverter->toNetworkUnits($withdrawal->network, $networkFeeNative);

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
    }

    /**
     * Record the gas each sent withdrawal really burned and charge or refund
     * the owner the difference against the buffered estimate they were billed.
     */
    public function reconcile(): void
    {
        Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->where('status', 'sent')
            ->whereNotNull('tx_hash')
            ->whereNotNull('network_fee_native')
            ->where('reconcile_attempts', '<', 5)
            ->where('sent_at', '>=', now()->subDays(7))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('gas_expenses')
                    ->whereColumn('gas_expenses.expensable_id', 'withdrawals.id')
                    ->where('gas_expenses.expensable_type', Withdrawal::class);
            })
            ->chunkById(100, function ($withdrawals): void {
                foreach ($withdrawals as $withdrawal) {
                    DB::transaction(fn () => $this->reconcileOne($withdrawal));
                }
            });
    }

    private function reconcileOne(Withdrawal $withdrawal): void
    {
        $withdrawal->increment('reconcile_attempts');

        if ($withdrawal->reconcile_attempts >= 5) {
            $this->finalizeReconciliation($withdrawal, (string) $withdrawal->network_fee_native);

            return;
        }

        $receipt = $this->broadcaster->getTransactionReceipt($withdrawal->network, (string) $withdrawal->tx_hash);

        if ($receipt === null || ($receipt['status'] ?? null) !== 'confirmed' || ! isset($receipt['fee'])) {
            return;
        }

        $this->finalizeReconciliation($withdrawal, (string) $receipt['fee']);
    }

    private function finalizeReconciliation(Withdrawal $withdrawal, string $actualNative): void
    {
        $varianceNative = bcsub($actualNative, (string) $withdrawal->network_fee_native, 8);
        $variance = $this->feeConverter->toNetworkUnits($withdrawal->network, $varianceNative);

        if (! GasExpense::query()->where('expensable_type', Withdrawal::class)->where('expensable_id', $withdrawal->id)->exists()) {
            GasExpense::create([
                'network' => $withdrawal->network,
                'tx_hash' => $withdrawal->tx_hash,
                'amount' => $actualNative,
                'expensable_type' => Withdrawal::class,
                'expensable_id' => $withdrawal->id,
            ]);
        }

        if ($variance === null || bccomp($variance, '0', 8) === 0) {
            return;
        }

        $balance = Balance::query()->withoutGlobalScope('owner')->lockForUpdate()->firstOrCreate(
            ['user_id' => $withdrawal->user_id, 'network' => $withdrawal->network],
            ['amount' => 0],
        );
        $balance->update(['amount' => bcsub((string) $balance->amount, $variance, 8)]);

        LedgerEntry::create([
            'user_id' => $withdrawal->user_id,
            'network' => $withdrawal->network,
            'amount' => bcmul($variance, '-1', 8),
            'reason' => 'network_fee_adjustment',
            'withdrawal_id' => $withdrawal->id,
        ]);
    }
}
