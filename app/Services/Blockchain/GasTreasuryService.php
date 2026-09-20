<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\DepositAddress;
use App\Models\EnergyRental;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\EnergyFloatLow;
use App\Notifications\LowGasAlert;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\EstimatesTransferFee;
use App\Services\Blockchain\Energy\TronSaveClient;
use App\Support\Network;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class GasTreasuryService
{
    private const TOPUP_STALE_MINUTES = 30;

    private TronSaveClient $tronSave;

    public function __construct(private readonly BlockchainBroadcaster $broadcaster, ?TronSaveClient $tronSave = null)
    {
        $this->tronSave = $tronSave ?? new TronSaveClient;
    }

    public function policy(string $network): GasPolicy
    {
        $nativeKey = Network::nativeKey($network);

        return GasPolicy::firstOrCreate(
            ['network' => $nativeKey],
            $this->defaultPolicy($nativeKey),
        );
    }

    /**
     * @param  int  $transfers  number of token transfers one top-up must fund
     *                          (1 + same-chain piggyback siblings)
     */
    public function ensureGasForSweep(string $network, int $recipientIndex, string $recipientAddress, ?TreasurySweep $sweep = null, int $transfers = 1): bool
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

        $tokenFee = bcmul($tokenFee, (string) max(1, $transfers), 8);

        $recipientBalance = $this->broadcaster->getNativeBalance($network, $recipientIndex);

        if ($recipientBalance === null) {
            return false;
        }

        if (bccomp($recipientBalance, $tokenFee, 8) >= 0) {
            return true;
        }

        if (Network::family($network) === 'tron' && $policy->energy_mode === 'rent') {
            $rented = $this->ensureEnergyViaRental(
                $network,
                $recipientIndex,
                $recipientAddress,
                'sweep',
                $sweep,
                $this->estimateNeededEnergy($tokenFee),
            );

            if ($rented === true) {
                return true;
            }

            if ($rented === false) {
                return false; // order in flight — the sweep retries next tick
            }
            // null → fall through to the existing burn/top-up path unchanged
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

        return $this->sendTopup($network, $wallet, $recipientAddress, $recipientIndex, $topupAmount, $topupFee);
    }

    public function ensureGasForWithdrawal(Withdrawal $withdrawal, ?string $estimatedFeeNative = null): bool
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

        if (Network::family($withdrawal->network) === 'tron' && $policy->energy_mode === 'rent') {
            $burnFee = $estimatedFeeNative ?? $this->estimateTransferFee($withdrawal->network, true, $withdrawal->destination_address);
            $rented = $burnFee === null ? null : $this->ensureEnergyViaRental(
                $withdrawal->network,
                (int) $wallet->derivation_index,
                (string) $wallet->address,
                'withdrawal',
                $withdrawal,
                $this->estimateNeededEnergy($burnFee),
            );

            if ($rented === true) {
                return true;
            }

            if ($rented === false) {
                return false; // rental ordered — send() blocks with energy_rental_pending
            }
            // null → burn fallback: continue to the reserve check below
        }

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

    /**
     * Energy the sweep/withdrawal needs, derived from the burn fee estimate.
     * The signer /fee returns TRX, not raw energy, so we invert the formula:
     * energy = (feeSun − bandwidth) / energyPrice.
     */
    public function estimateNeededEnergy(string $tokenFeeNative): int
    {
        $priceSun = (int) config('blockchain.tron_energy_price_sun', 100);
        $feeSun = (int) bcmul($tokenFeeNative, '1000000', 0);
        $energySun = max(0, $feeSun - 345000); // strip the bandwidth part
        // 1.2 headroom — the simulation under-measured a live holder payout by ~14%.
        $energy = $priceSun > 0 ? intdiv($energySun * 12 + ($priceSun * 10) - 1, $priceSun * 10) : 0;

        return max(65000, $energy);
    }

    /**
     * true = provisioned, false = wait (order in flight / bandwidth top-up pending),
     * null = fall back to burn.
     */
    public function ensureEnergyViaRental(
        string $network,
        int $receiverIndex,
        string $receiverAddress,
        string $purpose,
        ?Model $purposable,
        int $neededEnergy,
    ): ?bool {
        $policy = $this->policy($network);
        $wallet = TreasuryWallet::query()->where('network', $network)->first();

        if ($policy->energy_mode !== 'rent' || $wallet === null) {
            return null;
        }

        // (1) Already provisioned? Rented energy shows up in the account resource.
        $resource = $this->broadcaster->getTronResource($receiverIndex);

        if ($resource !== null) {
            // A fresh deposit address that only ever received USDT does not
            // exist on-chain — TronSave cannot delegate to it. A 0.1 TRX
            // top-up activates the account (the ~1 TRX creation fee burns
            // from the sender and is recorded via the top-up's gas expense);
            // the next tick sees the account activated and rents normally.
            if (($resource['activated'] ?? true) === false) {
                if ($receiverIndex === (int) $wallet->derivation_index) {
                    return null; // treasury is always activated — defensive
                }

                $this->sendTopup($network, $wallet, $receiverAddress, $receiverIndex, '0.10000000', '1.10000000');
                Log::info('energy.activation_topup', [
                    'network' => $network,
                    'receiver' => $receiverAddress,
                    'purpose' => $purpose,
                ]);

                return false;
            }

            $availableEnergy = (int) ($resource['energy_limit'] ?? 0) - (int) ($resource['energy_used'] ?? 0);
            $availableBandwidth = ((int) ($resource['bandwidth_limit'] ?? 0) - (int) ($resource['bandwidth_used'] ?? 0))
                + ((int) ($resource['free_bandwidth_limit'] ?? 0) - (int) ($resource['free_bandwidth_used'] ?? 0));

            if ($availableEnergy >= $neededEnergy && $availableBandwidth >= 345) {
                return true;
            }

            // (5) Energy fine, bandwidth short — a small top-up covers it; never more.
            if ($availableEnergy >= $neededEnergy) {
                // The treasury receiver (withdrawals/payouts) pays the ~0.345 TRX
                // bandwidth burn from its own balance — never top itself up.
                if ($receiverIndex === (int) $wallet->derivation_index) {
                    return true;
                }

                $this->sendTopup($network, $wallet, $receiverAddress, $receiverIndex, '0.40000000', '0.30000000');

                return false;
            }
        }

        // (2) An order is already in flight for this address.
        $ordered = EnergyRental::query()
            ->where('network', $network)
            ->where('receiver_address', $receiverAddress)
            ->where('status', 'ordered')
            ->where('expires_at', '>', now())
            ->exists();

        if ($ordered) {
            return false;
        }

        // A previously unfilled order for this same job — do not re-order; burn.
        // Scoped to a specific purposable: without one, a failed order would
        // block renting for that address forever.
        $failedForJob = $purposable !== null && EnergyRental::query()
            ->where('network', $network)
            ->where('receiver_address', $receiverAddress)
            ->where('status', 'failed')
            ->where('purposable_type', $purposable->getMorphClass())
            ->where('purposable_id', $purposable->getKey())
            ->exists();

        if ($failedForJob) {
            Log::warning('energy.rent_fallback', [
                'network' => $network,
                'receiver' => $receiverAddress,
                'purpose' => $purpose,
                'reason' => 'previous_order_unfilled',
            ]);

            return null;
        }

        // (3) Price / market guards. estimateTrx is in SUN despite the name.
        $estimate = $this->tronSave->estimate($receiverAddress, $neededEnergy, (int) $policy->rent_duration_sec);
        $burnCostSun = $neededEnergy * (int) config('blockchain.tron_energy_price_sun', 100);

        $fallback = match (true) {
            $estimate === null => 'estimate_unavailable',
            (int) ($estimate['availableResource'] ?? 0) < $neededEnergy => 'market_short',
            (int) ($estimate['unitPrice'] ?? PHP_INT_MAX) > (int) $policy->rent_max_price_sun => 'price_cap',
            (int) ($estimate['estimateTrx'] ?? PHP_INT_MAX) >= $burnCostSun => 'not_cheaper_than_burn',
            default => null,
        };

        if ($fallback !== null) {
            Log::warning('energy.rent_fallback', [
                'network' => $network,
                'receiver' => $receiverAddress,
                'purpose' => $purpose,
                'reason' => $fallback,
                'unit_price_sun' => $estimate['unitPrice'] ?? null,
            ]);

            return null;
        }

        // (4) Place the order.
        $orderId = $this->tronSave->buy($receiverAddress, $neededEnergy, (int) $policy->rent_duration_sec, (int) $policy->rent_max_price_sun);

        if ($orderId === null) {
            Log::warning('energy.rent_fallback', [
                'network' => $network,
                'receiver' => $receiverAddress,
                'purpose' => $purpose,
                'reason' => 'buy_failed',
            ]);

            return null;
        }

        EnergyRental::create([
            'network' => $network,
            'receiver_address' => $receiverAddress,
            'receiver_index' => $receiverIndex,
            'purpose' => $purpose,
            'purposable_type' => $purposable?->getMorphClass(),
            'purposable_id' => $purposable?->getKey(),
            'energy' => $neededEnergy,
            'duration_sec' => (int) $policy->rent_duration_sec,
            'order_id' => $orderId,
            'status' => 'ordered',
            'ordered_at' => now(),
            'expires_at' => now()->addSeconds((int) $policy->rent_duration_sec),
        ]);

        return false; // delegation lands seconds-to-minutes later; pollRentals() confirms it
    }

    public function hasPendingRental(Model $purposable): bool
    {
        return EnergyRental::query()
            ->where('purposable_type', $purposable->getMorphClass())
            ->where('purposable_id', $purposable->getKey())
            ->where('status', 'ordered')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * The rental fee estimate for a withdrawal/payout, or null when renting is
     * off / unavailable — callers then keep the burn estimate.
     */
    public function rentalFeeEstimateNative(string $network, int $neededEnergy): ?string
    {
        if (Network::family($network) !== 'tron' || $this->policy($network)->energy_mode !== 'rent') {
            return null;
        }

        $wallet = TreasuryWallet::query()->where('network', $network)->first();

        if ($wallet === null) {
            return null;
        }

        $estimate = $this->tronSave->estimate((string) $wallet->address, $neededEnergy, (int) $this->policy($network)->rent_duration_sec);

        // estimateTrx is SUN → TRX
        return isset($estimate['estimateTrx']) ? bcdiv((string) $estimate['estimateTrx'], '1000000', 8) : null;
    }

    public function pollRentals(): void
    {
        EnergyRental::query()->where('status', 'ordered')->chunkById(100, function ($rentals): void {
            foreach ($rentals as $rental) {
                $order = $this->tronSave->order((string) $rental->order_id);

                if ($order === null) {
                    continue; // transient failure — retry next tick
                }

                $fulfilled = (int) ($order['fulfilledPercent'] ?? 0);

                if ($fulfilled >= 100) {
                    $costNative = isset($order['payoutAmount'])
                        ? bcdiv((string) $order['payoutAmount'], '1000000', 8)
                        : null;

                    $rental->update([
                        'status' => 'filled',
                        'cost_native' => $costNative,
                        'unit_price_sun' => isset($order['price']) ? (int) $order['price'] : null,
                        'filled_at' => now(),
                    ]);

                    // Withdrawals: expensable = the rental itself, NOT the withdrawal.
                    // WithdrawalProcessor::reconcile() skips any withdrawal that already has a
                    // GasExpense (whereNotExists), so pointing this row at the withdrawal would
                    // silently disable the fee-variance refund. Sweeps/payouts keep their purposable.
                    $isWithdrawal = $rental->purposable_type === (new Withdrawal)->getMorphClass();

                    GasExpense::firstOrCreate(
                        ['energy_rental_id' => $rental->id],
                        [
                            'network' => $rental->network,
                            'tx_hash' => $order['delegates'][0]['txid'] ?? null,
                            'amount' => $costNative ?? '0.00000000',
                            'expensable_type' => $isWithdrawal ? $rental->getMorphClass() : $rental->purposable_type,
                            'expensable_id' => $isWithdrawal ? $rental->id : $rental->purposable_id,
                        ],
                    );

                    continue;
                }

                if ($rental->ordered_at !== null && $rental->ordered_at->lte(now()->subMinutes(10))) {
                    $rental->update([
                        'status' => 'failed',
                        'error_message' => 'Order unfilled after 10 minutes',
                    ]);
                }
            }
        });

        // Rental period lapsed — row is done, nothing more to poll.
        EnergyRental::query()
            ->where('status', 'filled')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function recoverStrandedGas(): void
    {
        foreach (Network::enabledKeys() as $network) {
            if (! Network::isToken($network)) {
                continue;
            }

            $minimum = config('blockchain.gas_recovery.min_native.'.Network::chain($network));

            if ($minimum === null) {
                continue;
            }

            $this->recoverStrandedGasFor($network, (string) $minimum);
        }
    }

    private function recoverStrandedGasFor(string $network, string $minimum): void
    {
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

        DepositAddress::query()
            ->where('network', $network)
            // Stranded TRX can only exist where we sent a top-up.
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('gas_topups')
                ->whereColumn('gas_topups.recipient_address', 'deposit_addresses.address')
                ->where('gas_topups.network', $network)
                ->where('gas_topups.kind', 'topup')
                ->where('gas_topups.status', 'confirmed'))
            // The shared EVM address holds deposits on sibling rows (other
            // networks), so an address with a credited unswept deposit on ANY
            // row sharing this address string still holds customer funds.
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('deposits')
                ->join('deposit_addresses as siblings', 'deposits.deposit_address_id', '=', 'siblings.id')
                ->whereColumn('siblings.address', 'deposit_addresses.address')
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

                    $chain = Network::chain($network);
                    $leave = (string) config("blockchain.gas_recovery.leave_native.{$chain}", '0.5');
                    $recoveryFee = bcadd((string) config("blockchain.gas_recovery.fee_native.{$chain}", '0.3'), '0', 8);
                    $amount = bcsub($native, $leave, 8);

                    [$topup, $created] = $this->findOrCreateOpenTopup(
                        $network,
                        (string) $wallet->address,
                        (int) $wallet->derivation_index,
                        (int) $wallet->id,
                        $amount,
                        'recovery',
                        $address->address,
                        (int) $address->derivation_index,
                    );

                    if (! $created) {
                        return false; // raced with another worker — pollTopups owns it now
                    }

                    $txHash = $this->broadcaster->broadcastTopUp(
                        $network,
                        (int) $address->derivation_index,   // source: the deposit address
                        (int) $wallet->derivation_index,    // destination: treasury
                        $amount,
                        $recoveryFee,
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

        $policy = Network::isToken($wallet->network)
            ? $this->policy($wallet->network)
            : null;

        if (Network::family($wallet->network) === 'tron') {
            $resource = $this->broadcaster->getTronResource((int) $wallet->derivation_index);

            if ($resource !== null) {
                $update['energy'] = $resource['energy_limit'] ?? null;
                $update['bandwidth'] = $resource['bandwidth_limit'] ?? null;
            }

            // Rent mode only — burn mode never touches TronSave, so no key is needed.
            if ($policy?->energy_mode === 'rent') {
                $info = $this->tronSave->userInfo();

                if ($info !== null) {
                    $update['rental_balance'] = bcdiv((string) ($info['balance'] ?? '0'), '1000000', 8);
                }
            }
        }

        $wallet->update($update);

        if ($policy !== null) {
            $this->alertIfNeeded($policy, $balance);

            if (Network::family($wallet->network) === 'tron' && $policy->energy_mode === 'rent') {
                $this->alertFloatIfNeeded($policy, $wallet->fresh()->rental_balance);
            }
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

        if (Network::family($wallet->network) === 'tron') {
            $resource = $this->broadcaster->getTronResource((int) $wallet->derivation_index);

            if ($resource !== null) {
                $update['energy'] = $resource['energy_limit'] ?? null;
                $update['bandwidth'] = $resource['bandwidth_limit'] ?? null;
            }
        }

        $wallet->update($update);

        return $balance;
    }

    private function sendTopup(string $network, TreasuryWallet $wallet, string $recipientAddress, int $recipientIndex, string $amount, string $fee): bool
    {
        [$topup, $created] = $this->findOrCreateOpenTopup($network, $recipientAddress, $recipientIndex, (int) $wallet->id, $amount);

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
            $amount,
            $fee,
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

    private function findOrCreateOpenTopup(string $network, string $recipientAddress, int $recipientIndex, int $walletId, string $amount, string $kind = 'topup', ?string $sourceAddress = null, ?int $sourceIndex = null): array
    {
        $now = now();
        $attributes = [
            'network' => $network,
            'kind' => $kind,
            'recipient_address' => $recipientAddress,
            'recipient_index' => $recipientIndex,
            'source_address' => $sourceAddress,
            'source_index' => $sourceIndex,
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

        $required = Network::confirmations($network);

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

    // Shares last_alert_at with alertIfNeeded — one alert per cooldown across gas+float.
    private function alertFloatIfNeeded(GasPolicy $policy, ?string $rentalBalance): bool
    {
        if ($rentalBalance === null || bccomp($rentalBalance, (string) $policy->rent_float_alert_trx, 8) >= 0) {
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

        Log::warning('energy.float_low', [
            'network' => $policy->network,
            'rental_balance' => $rentalBalance,
            'threshold' => (string) $policy->rent_float_alert_trx,
        ]);

        Notification::send($administrators, new EnergyFloatLow(
            $policy->network,
            $rentalBalance,
            (string) $policy->rent_float_alert_trx,
        ));
        $policy->update(['last_alert_at' => now()]);

        return true;
    }

    private function defaultPolicy(string $nativeKey): array
    {
        $amounts = match ($nativeKey) {
            'native_eth' => ['0.00500000', '0.00030000', '0.00100000'],
            'native_trx' => ['10.00000000', '1.00000000', '20.00000000'],
            'native_bnb' => ['0.00500000', '0.00020000', '0.00200000'],
            default => ['0.01000000', '0.02000000', '0.10000000'],
        };

        return [
            'reserve_threshold' => $amounts[0],
            'top_up_amount' => $amounts[1],
            'max_top_up' => $amounts[2],
            'manual_paused' => false,
            'alert_cooldown' => 60,
            'energy_mode' => 'burn',
            'rent_max_price_sun' => 90,
            'rent_duration_sec' => 3600,
            'rent_float_alert_trx' => '20.00000000',
        ];
    }
}
