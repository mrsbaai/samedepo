<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Balance;
use App\Models\EnergyRental;
use App\Models\GasExpense;
use App\Models\LedgerEntry;
use App\Models\TreasuryWallet;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\ReportsLastError;
use App\Support\Network;
use Illuminate\Support\Facades\DB;

class WithdrawalProcessor
{
    public function __construct(
        private readonly BlockchainBroadcaster $broadcaster,
        private ?GasTreasuryService $gasTreasury = null,
        private ?FeeConverter $feeConverter = null,
        private ?ConsolidationBiller $biller = null,
    ) {
        $this->gasTreasury ??= new GasTreasuryService($this->broadcaster);
        $this->feeConverter ??= new FeeConverter;
        $this->biller ??= new ConsolidationBiller($this->feeConverter);
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
            $this->block($withdrawal, 'treasury_insufficient_funds');

            return;
        }

        $estimatedFeeNative = $this->broadcaster->estimateWithdrawalFee($withdrawal);

        if ($estimatedFeeNative === null) {
            $this->block($withdrawal, 'fee_unavailable');

            return;
        }

        $burnFeeNative = $estimatedFeeNative;
        $rentalFee = $this->gasTreasury->rentalFeeEstimateNative(
            $withdrawal->network,
            $this->gasTreasury->estimateNeededEnergy($burnFeeNative),
        );

        if ($rentalFee !== null) {
            $estimatedFeeNative = $rentalFee;
        }

        $networkFeeNative = $this->feeConverter->bufferedNativeFee($estimatedFeeNative);
        $totalFee = $this->feeConverter->toNetworkUnits($withdrawal->network, $networkFeeNative);

        if ($totalFee === null) {
            $this->block($withdrawal, 'fee_conversion_failed');

            return;
        }

        // Outstanding sweep gas is charged inside the withdrawal (deducted from
        // amount_sent) instead of hitting an owner balance the withdrawal just
        // zeroed — settle() below marks it recovered after a successful send.
        $consolidation = $this->biller->outstanding($withdrawal->user_id, $withdrawal->network);

        if ($consolidation === null) {
            $this->block($withdrawal, 'fee_conversion_failed');

            return;
        }

        $amountSent = bcsub((string) $withdrawal->gross_amount, bcadd($totalFee, $consolidation, 8), 8);
        if (bccomp($amountSent, '0', 8) < 0) {
            $amountSent = '0.00000000';
        }

        $withdrawal->update([
            'network_fee' => $totalFee,
            'network_fee_native' => $networkFeeNative,
            'consolidation_fee' => $consolidation,
            'amount_sent' => $amountSent,
        ]);

        $isToken = Network::isToken($withdrawal->network);
        if ($isToken && ! $this->gasTreasury->ensureGasForWithdrawal($withdrawal, $burnFeeNative)) {
            $this->block($withdrawal, $this->gasTreasury->hasPendingRental($withdrawal) ? 'energy_rental_pending' : 'gas_unavailable');

            return;
        }

        $txHash = $this->broadcaster->broadcastWithdrawal($withdrawal);

        if ($txHash === null) {
            $reason = $this->broadcaster instanceof ReportsLastError ? $this->broadcaster->lastError() : null;
            $this->block($withdrawal, $reason ?? 'broadcast_failed: no transaction hash');

            return;
        }

        $withdrawal->update([
            'status' => 'sent',
            'last_error' => null,
            'tx_hash' => $txHash,
            'sent_at' => now(),
        ]);

        $treasurySpend = Network::isNative($withdrawal->network)
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

        if (bccomp($consolidation, '0', 8) > 0) {
            $this->biller->settle(
                $withdrawal->user_id,
                $withdrawal->network,
                $consolidation,
                $withdrawal->id,
                chargeBalance: false,
            );
        }
    }

    private function block(Withdrawal $withdrawal, string $code): void
    {
        $withdrawal->update([
            'last_error' => mb_substr($code, 0, 255),
            'attempts' => $withdrawal->attempts + 1,
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

        $rental = EnergyRental::query()
            ->where('purposable_type', (new Withdrawal)->getMorphClass())
            ->where('purposable_id', $withdrawal->id)
            // filled or expired — the cost was paid either way.
            ->whereIn('status', ['filled', 'expired'])
            ->first();

        // Owner's real cost = what the receipt burned (≈ bandwidth, or the full
        // energy when we fell back to burn) + the rental paid from the float.
        $actualNative = bcadd((string) $receipt['fee'], $rental?->cost_native !== null ? (string) $rental->cost_native : '0', 8);
        $this->finalizeReconciliation($withdrawal, $actualNative, (string) $receipt['fee']);
    }

    private function finalizeReconciliation(Withdrawal $withdrawal, string $actualNative, ?string $expenseNative = null): void
    {
        $varianceNative = bcsub($actualNative, (string) $withdrawal->network_fee_native, 8);
        $variance = $this->feeConverter->toNetworkUnits($withdrawal->network, $varianceNative);

        if (! GasExpense::query()->where('expensable_type', Withdrawal::class)->where('expensable_id', $withdrawal->id)->exists()) {
            GasExpense::create([
                'network' => $withdrawal->network,
                'tx_hash' => $withdrawal->tx_hash,
                'amount' => $expenseNative ?? $actualNative,
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
