<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\LowGasAlert;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class GasTreasuryService
{
    public function __construct(private readonly BlockchainBroadcaster $broadcaster) {}

    public function policy(string $network): GasPolicy
    {
        return GasPolicy::firstOrCreate(
            ['network' => $network],
            $this->defaultPolicy($network),
        );
    }

    public function ensureGasForSweep(string $network, int $recipientIndex, string $recipientAddress): bool
    {
        $policy = $this->policy($network);

        if ($policy->manual_paused) {
            return false;
        }

        $wallet = TreasuryWallet::query()->where('network', $network)->first();

        if ($wallet === null) {
            return false;
        }

        $tokenFee = $this->broadcaster->estimateFee($network, true);

        if ($tokenFee === null) {
            return false;
        }

        $recipientBalance = $this->broadcaster->getNativeBalance($network, $recipientIndex);

        if ($recipientBalance === null) {
            return false;
        }

        if (bccomp($recipientBalance, $tokenFee, 8) >= 0) {
            return true;
        }

        $topupAmount = $this->chooseTopupAmount($tokenFee, $policy);

        if ($topupAmount === null) {
            return false;
        }

        $topupFee = $this->broadcaster->estimateFee($network, false);

        if ($topupFee === null) {
            return false;
        }

        $treasuryBalance = $this->refreshTreasuryBalance($wallet);

        if ($treasuryBalance === null) {
            return false;
        }

        $remainingAfterTopup = bcsub($treasuryBalance, bcadd($topupAmount, $topupFee, 8), 8);

        if (bccomp($remainingAfterTopup, (string) $policy->reserve_threshold, 8) < 0) {
            $this->alertIfNeeded($policy, $remainingAfterTopup);

            return false;
        }

        [$topup, $created] = $this->findOrCreateOpenTopup($network, $recipientAddress, $recipientIndex, (int) $wallet->id, $topupAmount);

        if ($topup === null) {
            return false;
        }

        if (! $created) {
            return $this->pollSingleTopup($topup);
        }

        $txHash = $this->broadcaster->broadcastTopUp(
            $network,
            (int) $wallet->derivation_index,
            $recipientIndex,
            $topupAmount,
            $topupFee,
        );

        if ($txHash === null) {
            $this->markTopupFailed($topup, 'Broadcast failed');

            return false;
        }

        $topup->update([
            'tx_hash' => $txHash,
            'broadcasted_at' => now(),
        ]);

        $receipt = $this->broadcaster->getTransactionReceipt($network, $txHash);

        if ($receipt === null) {
            return false;
        }

        if ($receipt['status'] === 'failed') {
            $this->markTopupFailed($topup, 'Receipt failed');

            return false;
        }

        if ($this->isConfirmed($receipt, $network)) {
            $this->confirmTopup($topup, $receipt);

            return true;
        }

        return false;
    }

    public function ensureGasForWithdrawal(Withdrawal $withdrawal): bool
    {
        $policy = $this->policy($withdrawal->network);

        if ($policy->manual_paused) {
            return false;
        }

        $wallet = TreasuryWallet::query()->where('network', $withdrawal->network)->first();

        if ($wallet === null) {
            return false;
        }

        $balance = $this->broadcaster->getNativeBalance($withdrawal->network, (int) $wallet->derivation_index);

        if ($balance === null) {
            return false;
        }

        $wallet->update([
            'native_balance' => $balance,
            'refreshed_at' => now(),
        ]);

        if (bccomp($balance, (string) $policy->reserve_threshold, 8) >= 0) {
            return true;
        }

        $this->alertIfNeeded($policy, $balance);

        return false;
    }

    public function pollTopups(): void
    {
        GasTopup::query()
            ->where('status', 'broadcast')
            ->chunkById(100, function ($topups): void {
                foreach ($topups as $topup) {
                    $this->pollSingleTopup($topup);
                }
            });
    }

    public function refreshTreasuryWallet(TreasuryWallet $wallet): ?array
    {
        $balance = $this->broadcaster->getNativeBalance($wallet->network, (int) $wallet->derivation_index);

        if ($balance === null) {
            return null;
        }

        $update = [
            'native_balance' => $balance,
            'refreshed_at' => now(),
        ];

        if ($wallet->network === 'usdt_trc20') {
            $resource = $this->broadcaster->getTronResource((int) $wallet->derivation_index);

            if ($resource !== null) {
                $update['energy'] = $resource['energy_limit'] ?? null;
                $update['bandwidth'] = $resource['bandwidth_limit'] ?? null;
            }
        }

        $wallet->update($update);

        if (in_array($wallet->network, ['usdt_erc20', 'usdt_base', 'usdt_trc20'], true)) {
            $this->alertIfNeeded($this->policy($wallet->network), $balance);
        }

        return $update;
    }

    private function chooseTopupAmount(string $tokenFee, GasPolicy $policy): ?string
    {
        $amount = bccomp($tokenFee, (string) $policy->top_up_amount, 8) > 0
            ? $tokenFee
            : (string) $policy->top_up_amount;

        if (bccomp($amount, (string) $policy->max_top_up, 8) > 0) {
            return null;
        }

        return $amount;
    }

    private function refreshTreasuryBalance(TreasuryWallet $wallet): ?string
    {
        $balance = $this->broadcaster->getNativeBalance($wallet->network, (int) $wallet->derivation_index);

        if ($balance === null) {
            return null;
        }

        $update = [
            'native_balance' => $balance,
            'refreshed_at' => now(),
        ];

        if ($wallet->network === 'usdt_trc20') {
            $resource = $this->broadcaster->getTronResource((int) $wallet->derivation_index);

            if ($resource !== null) {
                $update['energy'] = $resource['energy_limit'] ?? null;
                $update['bandwidth'] = $resource['bandwidth_limit'] ?? null;
            }
        }

        $wallet->update($update);

        return $balance;
    }

    private function findOrCreateOpenTopup(string $network, string $recipientAddress, int $recipientIndex, int $walletId, string $amount): array
    {
        $now = now();
        $attributes = [
            'network' => $network,
            'recipient_address' => $recipientAddress,
            'recipient_index' => $recipientIndex,
            'treasury_wallet_id' => $walletId,
            'amount' => $amount,
            'status' => 'broadcast',
            'is_open' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $created = (bool) DB::table('gas_topups')->insertOrIgnore([$attributes]);

        $topup = GasTopup::query()
            ->where('network', $network)
            ->where('recipient_address', $recipientAddress)
            ->where('is_open', 'open')
            ->first();

        return [$topup, $created];
    }

    private function pollSingleTopup(GasTopup $topup): bool
    {
        if ($topup->tx_hash === null) {
            return false;
        }

        $receipt = $this->broadcaster->getTransactionReceipt($topup->network, $topup->tx_hash);

        if ($receipt === null) {
            return false;
        }

        if ($receipt['status'] === 'failed') {
            $this->markTopupFailed($topup, 'Receipt failed');

            return false;
        }

        if ($this->isConfirmed($receipt, $topup->network)) {
            $this->confirmTopup($topup, $receipt);

            return true;
        }

        return false;
    }

    private function isConfirmed(array $receipt, string $network): bool
    {
        if (($receipt['status'] ?? 'pending') !== 'confirmed') {
            return false;
        }

        $required = (int) config("blockchain.confirmations.{$network}", 0);

        return ($receipt['confirmations'] ?? 0) >= $required;
    }

    private function markTopupFailed(GasTopup $topup, string $message): void
    {
        $topup->update([
            'status' => 'failed',
            'error_message' => $message,
            'is_open' => (string) $topup->id,
        ]);
    }

    private function confirmTopup(GasTopup $topup, array $receipt): void
    {
        $topup->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'is_open' => (string) $topup->id,
        ]);

        GasExpense::firstOrCreate(
            ['gas_topup_id' => $topup->id],
            [
                'network' => $topup->network,
                'tx_hash' => $topup->tx_hash,
                'amount' => $receipt['fee'] ?? '0.00000000',
                'expensable_type' => GasTopup::class,
                'expensable_id' => $topup->id,
            ]
        );
    }

    private function alertIfNeeded(GasPolicy $policy, ?string $balance): bool
    {
        if ($balance === null || bccomp($balance, (string) $policy->reserve_threshold, 8) >= 0) {
            return false;
        }

        if ($policy->last_alert_at !== null && $policy->last_alert_at->diffInMinutes(now()) < $policy->alert_cooldown) {
            return false;
        }

        $administrators = User::query()
            ->where('role', 'admin')
            ->where('is_admin', true)
            ->where('is_active', true)
            ->get();

        if ($administrators->isEmpty()) {
            return false;
        }

        Notification::send($administrators, new LowGasAlert(
            $policy->network,
            $balance,
            (string) $policy->reserve_threshold,
        ));
        $policy->update(['last_alert_at' => now()]);

        return true;
    }

    private function defaultPolicy(string $network): array
    {
        $amounts = match ($network) {
            'usdt_erc20' => ['0.05000000', '0.02000000', '0.10000000'],
            'usdt_base' => ['0.00500000', '0.01000000', '0.05000000'],
            'usdt_trc20' => ['100.00000000', '200.00000000', '1000.00000000'],
            default => ['0.01000000', '0.02000000', '0.10000000'],
        };

        return [
            'reserve_threshold' => $amounts[0],
            'top_up_amount' => $amounts[1],
            'max_top_up' => $amounts[2],
            'manual_paused' => false,
            'alert_cooldown' => 60,
        ];
    }
}
