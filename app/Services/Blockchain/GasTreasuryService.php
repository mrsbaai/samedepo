<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\DepositAddress;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\LowGasAlert;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\EstimatesTransferFee;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class GasTreasuryService
{
    private const TOPUP_STALE_MINUTES = 30;

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

        $tokenFee = $this->estimateTransferFee($network, true, (string) $wallet->address, $recipientIndex);

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

        $inFlight = GasTopup::query()
            ->where('network', $network)
            ->where('kind', 'topup')
            ->where('status', 'broadcast')
            ->whereNotNull('tx_hash')
            ->whereNull('confirmed_at')
            ->where('recipient_address', '!=', $recipientAddress)
            ->exists();

        if ($inFlight) {
            Log::debug('gas.topup_deferred', ['network' => $network, 'recipient' => $recipientAddress]);

            return false;
        }

        $topupAmount = $this->chooseTopupAmount($tokenFee, $recipientBalance, $policy);

        if ($topupAmount === null) {
            return false;
        }

        $topupFee = $this->estimateTransferFee($network, false, $recipientAddress, (int) $wallet->derivation_index);

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
                    if ($this->expireIfStale($topup)) {
                        continue;
                    }

                    $this->pollSingleTopup($topup);
                }
            });
    }

    public function recoverStrandedGas(): void
    {
        $network = 'usdt_trc20';
        $wallet = TreasuryWallet::query()->where('network', $network)->first();

        if ($wallet === null) {
            return;
        }

        // One recovery in flight at a time — pollTopups() confirms/expires it.
        $recoveryInFlight = GasTopup::query()
            ->where('network', $network)
            ->where('kind', 'recovery')
            ->where('is_open', 'open')
            ->exists();

        if ($recoveryInFlight) {
            return;
        }

        $minimum = (string) config('blockchain.gas_recovery.min_native.usdt_trc20', '5');

        DepositAddress::query()
            ->where('network', $network)
            // Stranded TRX can only exist where we sent a top-up.
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('gas_topups')
                ->whereColumn('gas_topups.recipient_address', 'deposit_addresses.address')
                ->where('gas_topups.network', $network)
                ->where('gas_topups.kind', 'topup')
                ->where('gas_topups.status', 'confirmed'))
            ->whereDoesntHave('deposits', fn ($query) => $query
                ->where('deposits.status', 'credited')
                ->whereNull('deposits.swept_at'))
            ->chunkById(100, function ($addresses) use ($network, $wallet, $minimum) {
                foreach ($addresses as $address) {
                    $topupOpen = GasTopup::query()
                        ->where('network', $network)
                        ->where('recipient_address', $address->address)
                        ->where('is_open', 'open')
                        ->exists();
                    if ($topupOpen) {
                        continue;
                    }

                    $held = $this->broadcaster->getTokenBalance($network, (int) $address->derivation_index);
                    if ($held === null || bccomp($held, '0', 8) !== 0) {
                        continue; // null = provider hiccup; non-zero = still holds tokens
                    }

                    $native = $this->broadcaster->getNativeBalance($network, (int) $address->derivation_index);
                    if ($native === null || bccomp($native, $minimum, 8) < 0) {
                        continue;
                    }

                    $amount = bcsub($native, '0.50000000', 8); // leave 0.5 TRX for future bandwidth

                    [$topup, $created] = $this->findOrCreateOpenTopup(
                        $network,
                        (string) $wallet->address,
                        (int) $wallet->derivation_index,
                        (int) $wallet->id,
                        $amount,
                        'recovery',
                    );

                    if (! $created) {
                        return false; // raced with another worker — pollTopups owns it now
                    }

                    $txHash = $this->broadcaster->broadcastTopUp(
                        $network,
                        (int) $address->derivation_index,   // source: the deposit address
                        (int) $wallet->derivation_index,    // destination: treasury
                        $amount,
                        '0.30000000',
                    );

                    if ($txHash === null) {
                        $this->markTopupFailed($topup, 'Broadcast failed');

                        return false;
                    }

                    $topup->update(['tx_hash' => $txHash, 'broadcasted_at' => now()]);

                    $receipt = $this->broadcaster->getTransactionReceipt($network, $txHash);
                    if ($receipt !== null && $receipt['status'] === 'failed') {
                        $this->markTopupFailed($topup, 'Receipt failed');
                    } elseif ($receipt !== null && $this->isConfirmed($receipt, $network)) {
                        $this->confirmTopup($topup, $receipt);
                    }

                    return false; // at most one new recovery per run (false stops chunkById)
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

        if (in_array($wallet->network, ['usdt_erc20', 'usdt_trc20'], true)) {
            $this->alertIfNeeded($this->policy($wallet->network), $balance);
        }

        return $update;
    }

    public function refreshStaleTreasuryWallets(): void
    {
        $cutoff = now()->subSeconds(60);

        TreasuryWallet::query()
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('refreshed_at')
                    ->orWhere('refreshed_at', '<=', $cutoff);
            })
            ->get()
            ->each(function (TreasuryWallet $wallet): void {
                $lock = Cache::lock("treasury-wallet-refresh:{$wallet->id}", 55);

                if (! $lock->get()) {
                    return;
                }

                try {
                    $this->refreshTreasuryWallet($wallet);
                } finally {
                    $lock->release();
                }
            });
    }

    private function estimateTransferFee(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?string
    {
        if ($this->broadcaster instanceof EstimatesTransferFee) {
            return $this->broadcaster->estimateTransferFee($network, $tokenTransfer, $destination, $sourceIndex);
        }

        return $this->broadcaster->estimateFee($network, $tokenTransfer);
    }

    private function chooseTopupAmount(string $tokenFee, string $recipientBalance, GasPolicy $policy): ?string
    {
        $needed = bcsub((new FeeConverter)->bufferedNativeFee($tokenFee), $recipientBalance, 8);
        $amount = bccomp($needed, (string) $policy->top_up_amount, 8) > 0
            ? $needed
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

    private function findOrCreateOpenTopup(string $network, string $recipientAddress, int $recipientIndex, int $walletId, string $amount, string $kind = 'topup'): array
    {
        $now = now();
        $attributes = [
            'network' => $network,
            'kind' => $kind,
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
            ->where('kind', $kind)
            ->where('recipient_address', $recipientAddress)
            ->where('is_open', 'open')
            ->first();

        return [$topup, $created];
    }

    private function expireIfStale(GasTopup $topup): bool
    {
        $cutoff = now()->subMinutes(self::TOPUP_STALE_MINUTES);

        if ($topup->tx_hash === null) {
            if ($topup->created_at !== null && $topup->created_at->lt($cutoff)) {
                $this->markTopupFailed($topup, 'Dropped: never broadcast');

                return true;
            }

            return false;
        }

        if ($topup->broadcasted_at === null || $topup->broadcasted_at->gte($cutoff)) {
            return false;
        }

        $receipt = $this->broadcaster->getTransactionReceipt($topup->network, $topup->tx_hash);
        $unseen = $receipt === null || (($receipt['status'] ?? 'pending') === 'pending' && (int) ($receipt['confirmations'] ?? 0) === 0);

        if ($unseen) {
            $this->markTopupFailed($topup, 'Dropped: no on-chain receipt after '.self::TOPUP_STALE_MINUTES.' minutes');

            return true;
        }

        return false;
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
            'usdt_erc20' => ['0.00500000', '0.00030000', '0.00100000'],
            'usdt_trc20' => ['10.00000000', '1.00000000', '20.00000000'],
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
