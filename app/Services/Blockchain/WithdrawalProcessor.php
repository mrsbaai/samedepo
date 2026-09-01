<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\GasExpense;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;

class WithdrawalProcessor
{
    public function __construct(
        private readonly BlockchainBroadcaster $broadcaster,
        private ?GasTreasuryService $gasTreasury = null,
    ) {
        $this->gasTreasury ??= new GasTreasuryService($this->broadcaster);
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

        $bufferPercent = (string) PlatformSettings::instance()->withdrawal_fee_buffer_percent;
        $chargedFeeNative = bcmul(
            $estimatedFeeNative,
            bcdiv(bcadd('100', $bufferPercent, 8), '100', 8),
            8,
        );

        $tokenNetworks = ['usdt_erc20', 'usdt_trc20'];
        $isToken = in_array($withdrawal->network, $tokenNetworks, true);

        if ($isToken) {
            if (! $this->gasTreasury->ensureGasForWithdrawal($withdrawal)) {
                return;
            }
        }

        $totalFee = $this->calculateTotalFee($withdrawal, $chargedFeeNative);

        if ($totalFee === null) {
            return;
        }

        $amountSent = bccomp((string) $withdrawal->gross_amount, $totalFee, 8) >= 0
            ? bcsub((string) $withdrawal->gross_amount, $totalFee, 8)
            : '0.00000000';

        $withdrawal->update([
            'network_fee' => $totalFee,
            'amount_sent' => $amountSent,
        ]);

        $txHash = $this->broadcaster->broadcastWithdrawal($withdrawal);

        if ($txHash === null) {
            return;
        }

        $withdrawal->update([
            'status' => 'sent',
            'tx_hash' => $txHash,
            'sent_at' => now(),
        ]);

        $wallet->available_funds = bcsub((string) $wallet->available_funds, $amountSent, 8);
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

    private function calculateTotalFee(Withdrawal $withdrawal, string $chargedFeeNative): ?string
    {
        $tokenNetworks = ['usdt_erc20', 'usdt_trc20'];
        $isToken = in_array($withdrawal->network, $tokenNetworks, true);
        $recoveryNative = $this->unrecoveredSweepGasNative($withdrawal);

        if (! $isToken) {
            return bcadd($chargedFeeNative, $recoveryNative, 8);
        }

        $nativeKey = $withdrawal->network === 'usdt_trc20' ? 'native_trx' : 'native_eth';
        $nativeValuation = UsdValuation::query()->where('network', $nativeKey)->value('conversion_value');
        $tokenValuation = UsdValuation::query()->where('network', $withdrawal->network)->value('conversion_value');

        if ($nativeValuation === null || $tokenValuation === null) {
            return null;
        }

        $nativeUsd = (string) $nativeValuation;
        $tokenUsd = (string) $tokenValuation;

        if (bccomp($nativeUsd, '0', 8) <= 0 || bccomp($tokenUsd, '0', 8) <= 0) {
            return null;
        }

        $feeTokens = bcdiv(bcmul($chargedFeeNative, $nativeUsd, 8), $tokenUsd, 8);
        $recoveryTokens = bcdiv(bcmul($recoveryNative, $nativeUsd, 8), $tokenUsd, 8);

        return bcadd($feeTokens, $recoveryTokens, 8);
    }

    private function unrecoveredSweepGasNative(Withdrawal $withdrawal): string
    {
        $sum = GasExpense::query()
            ->where('gas_expenses.expensable_type', TreasurySweep::class)
            ->join('treasury_sweeps', 'treasury_sweeps.id', '=', 'gas_expenses.expensable_id')
            ->where('treasury_sweeps.network', $withdrawal->network)
            ->where('treasury_sweeps.status', 'confirmed')
            ->whereNull('treasury_sweeps.fee_recovered_at')
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
            ->sum('gas_expenses.amount');

        return $sum !== null ? (string) $sum : '0.00000000';
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
