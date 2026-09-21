<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\DepositAddress;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Support\Network;
use Illuminate\Support\Facades\Cache;

/**
 * One honest withdrawal-fee quote for owner and admin surfaces: the network
 * fee (rental-aware), consolidation already incurred, and the sweeps still
 * needed to fund the withdrawal. Send-side code bills only the first two;
 * consolidation_pending is informational — those costs are charged once the
 * sweeps actually run.
 */
class WithdrawalQuote
{
    private ?string $failure = null;

    private bool $fresh = false;

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

    public function lastFailure(): ?string
    {
        return $this->failure;
    }

    public function quote(
        int $userId,
        string $network,
        string $grossAmount,
        ?Withdrawal $withdrawal = null,
        ?string $destination = null,
        bool $fresh = false,
    ): ?array {
        $this->failure = null;
        $this->fresh = $fresh;
        $isToken = Network::isToken($network);
        $nativeKey = Network::nativeKey($network);
        $destination ??= $withdrawal?->destination_address;
        $bufferPercent = (string) PlatformSettings::instance()->withdrawal_fee_buffer_percent;

        try {
            $estimateNative = $this->remember(
                'withdraw-fee-estimate:'.$network.':'.md5($destination ?? '-'),
                fn (): ?string => $withdrawal !== null
                    ? $this->broadcaster->estimateWithdrawalFee($withdrawal)
                    : ($destination !== null
                        ? $this->broadcaster->estimateTransferResources($network, $isToken, $destination)['fee'] ?? null
                        : $this->broadcaster->estimateFee($network, tokenTransfer: $isToken)),
            );
        } catch (\Throwable) {
            $estimateNative = null;
        }

        if ($estimateNative === null) {
            return $this->fail('fee_unavailable');
        }

        $method = $isToken ? 'burn' : (Network::family($network) === 'utxo' ? 'miner' : 'gas');

        if (Network::family($network) === 'tron' && $this->gasTreasury->policy($network)->energy_mode === 'rent') {
            $resources = $this->transferResources($network, $destination, null);
            $rental = $this->rentalEstimate(
                $network,
                $this->gasTreasury->estimateNeededEnergy($resources['energy'] ?? null, $network),
            );

            if ($rental !== null) {
                $estimateNative = $rental;
                $method = 'rental';
            }
        }

        $bufferedNative = bcmul($estimateNative, bcadd('1', bcdiv($bufferPercent, '100', 8), 8), 8);
        $networkFee = $this->feeConverter->toNetworkUnits($network, $bufferedNative);

        if ($networkFee === null) {
            return $this->fail('fee_conversion_failed');
        }

        $outstanding = $this->biller->outstanding($userId, $network);

        if ($outstanding === null) {
            return $this->fail('fee_conversion_failed');
        }

        $pending = $this->pendingSweepCost($userId, $network, $grossAmount);

        if ($pending === null && $this->failure !== null) {
            return null;
        }

        $totalFee = bcadd($networkFee, bcadd($outstanding, $pending['amount'] ?? '0.00000000', 8), 8);
        $receive = bcsub($grossAmount, $totalFee, 8);

        if (bccomp($receive, '0', 8) < 0) {
            $receive = '0.00000000';
        }

        $nativeUsd = UsdValuation::query()->where('network', $nativeKey)->value('conversion_value');
        $tokenUsd = $isToken
            ? UsdValuation::query()->where('network', $network)->value('conversion_value')
            : $nativeUsd;

        return [
            'network' => $network,
            'gross' => $grossAmount,
            'network_fee' => [
                'method' => $method,
                'estimate_native' => $estimateNative,
                'buffer_percent' => $bufferPercent,
                'buffered_native' => $bufferedNative,
                'native_symbol' => Network::nativeSymbol($network),
                'native_usd' => (string) ($nativeUsd ?? '0'),
                'token_usd' => (string) ($tokenUsd ?? '0'),
                'amount' => $networkFee,
            ],
            'consolidation_outstanding' => [
                'native' => $this->feeConverter->unrecoveredSweepGasNative($userId, $network),
                'amount' => $outstanding,
                'items' => [
                    'topups' => $this->feeConverter->attributableTopupQuery($userId, $network)
                        ->whereNull('gas_topups.fee_recovered_at')->count(),
                    'rentals' => $this->feeConverter->attributableRentalQuery($userId, $network)
                        ->whereNull('energy_rentals.fee_recovered_at')->count(),
                    'sweeps' => $this->unrecoveredSweepCount($userId, $network),
                ],
            ],
            'consolidation_pending' => $pending,
            'total_fee' => $totalFee,
            'receive' => $receive,
        ];
    }

    /**
     * Estimated native cost of sweeping the owner's still-unswept credited
     * deposits — only relevant when the treasury cannot cover the gross amount.
     * Null when the treasury covers the withdrawal outright, or on estimation
     * failure (lastFailure() distinguishes the two).
     *
     * @return array{native: string, amount: string, addresses: int}|null
     */
    private function pendingSweepCost(int $userId, string $network, string $grossAmount): ?array
    {
        $wallet = TreasuryWallet::query()->where('network', $network)->first();

        if ($wallet !== null && bccomp((string) $wallet->available_funds, $grossAmount, 8) >= 0) {
            return null;
        }

        $addresses = DepositAddress::query()
            ->withoutGlobalScope('owner')
            ->where('network', $network)
            ->whereHas('customer', fn ($query) => $query->where('user_id', $userId))
            ->whereHas('deposits', fn ($query) => $query->where('status', 'credited')->whereNull('swept_at'))
            ->get();

        $native = '0.00000000';
        $isToken = Network::isToken($network);
        $tronRent = Network::family($network) === 'tron'
            && $this->gasTreasury->policy($network)->energy_mode === 'rent';

        foreach ($addresses as $address) {
            $index = (int) $address->derivation_index;

            if ($tronRent) {
                $resources = $this->transferResources($network, (string) $wallet?->address, $index);

                if ($resources === null) {
                    return $this->fail('fee_unavailable');
                }

                $rental = $this->gasTreasury->rentalFeeEstimateNative(
                    $network,
                    $this->gasTreasury->estimateNeededEnergy($resources['energy'] ?? null, $network),
                );

                if ($rental === null) {
                    return $this->fail('fee_unavailable');
                }

                $native = bcadd($native, $rental, 8);

                $resource = $this->tronResource($index);

                if (($resource['activated'] ?? true) === false) {
                    // 0.1 TRX activation top-up + the ~1 TRX account-creation burn.
                    $native = bcadd($native, '1.10000000', 8);
                }
            } elseif ($isToken) {
                // Sweep burn fee plus the treasury→address gas top-up tx fee —
                // the owner is billed for both (FeeConverter::sweepGasNative).
                $resources = $this->transferResources($network, (string) ($wallet?->address ?? ''), $index);
                $topup = $this->transferResources($network, (string) $address->address, (int) ($wallet?->derivation_index ?? 0), false);
                $fee = $resources['fee'] ?? null;
                $topupFee = $topup['fee'] ?? null;

                if ($fee === null || $topupFee === null) {
                    return $this->fail('fee_unavailable');
                }

                $native = bcadd($native, bcadd($fee, $topupFee, 8), 8);
            } else {
                try {
                    $fee = $this->remember(
                        'withdraw-fee-estimate:'.$network.':native',
                        fn (): ?string => $this->broadcaster->estimateFee($network, tokenTransfer: false),
                    );
                } catch (\Throwable) {
                    $fee = null;
                }

                if ($fee === null) {
                    return $this->fail('fee_unavailable');
                }

                $native = bcadd($native, $fee, 8);
            }
        }

        $amount = $this->feeConverter->toNetworkUnits($network, $native);

        if ($amount === null) {
            return $this->fail('fee_conversion_failed');
        }

        return ['native' => $native, 'amount' => $amount, 'addresses' => $addresses->count()];
    }

    private function transferResources(string $network, ?string $destination, ?int $sourceIndex, bool $tokenTransfer = true): ?array
    {
        try {
            return $this->remember(
                'withdraw-transfer-resources:'.$network.':'.($tokenTransfer ? 't' : 'n').':'.($sourceIndex ?? '-').':'.($destination ?? '-'),
                fn (): ?array => $this->broadcaster->estimateTransferResources($network, $tokenTransfer, $destination, $sourceIndex),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function tronResource(int $index): ?array
    {
        try {
            return $this->remember(
                'tron-resource:'.$index,
                fn (): ?array => $this->broadcaster->getTronResource($index),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function rentalEstimate(string $network, int $neededEnergy): ?string
    {
        try {
            return $this->remember(
                'withdraw-rental-estimate:'.$network.':'.$neededEnergy,
                fn (): ?string => $this->gasTreasury->rentalFeeEstimateNative($network, $neededEnergy),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 300s cache for previews; send() passes fresh: true so the locked fee is
     * always measured at send time — the buffer/reconciliation absorb drift.
     */
    private function remember(string $key, callable $fn): mixed
    {
        if ($this->fresh) {
            return $fn();
        }

        return Cache::remember($key, 300, $fn);
    }

    private function unrecoveredSweepCount(int $userId, string $network): int
    {
        return TreasurySweep::query()
            ->where('network', $network)
            ->where('status', 'confirmed')
            ->whereNull('fee_recovered_at')
            ->where(function ($query) use ($userId): void {
                $query->whereExists(function ($sub) use ($userId): void {
                    $sub->selectRaw('1')->from('deposits')
                        ->whereColumn('deposits.id', 'treasury_sweeps.deposit_id')
                        ->where('deposits.user_id', $userId);
                })->orWhereExists(function ($sub) use ($userId): void {
                    $sub->selectRaw('1')->from('deposit_addresses')
                        ->join('customers', 'customers.id', '=', 'deposit_addresses.customer_id')
                        ->whereColumn('deposit_addresses.id', 'treasury_sweeps.deposit_address_id')
                        ->where('customers.user_id', $userId);
                });
            })
            ->count();
    }

    private function fail(string $code): null
    {
        $this->failure = $code;

        return null;
    }

    /**
     * Flatten a quote for Blade: the structured detail stays under
     * network_fee_detail while the legacy top-level aliases (network_fee,
     * total_fee, receive) keep the current templates working.
     */
    public function display(array $quote): array
    {
        $detail = $quote['network_fee'];
        $items = $quote['consolidation_outstanding']['items'];
        $outstandingParts = array_filter([
            $items['topups'] > 0 ? $items['topups'].' top-up'.($items['topups'] > 1 ? 's' : '') : null,
            $items['rentals'] > 0 ? $items['rentals'].' energy rental'.($items['rentals'] > 1 ? 's' : '') : null,
            $items['sweeps'] > 0 ? $items['sweeps'].' sweep'.($items['sweeps'] > 1 ? 's' : '') : null,
        ]);
        $pending = $quote['consolidation_pending'];

        return array_merge($quote, [
            'is_token' => Network::isToken($quote['network']),
            'network_fee_detail' => $detail,
            'network_fee' => $detail['amount'],
            'method_label' => match ($detail['method']) {
                'rental' => 'rented TRON energy',
                'miner' => 'miner fee',
                default => 'gas',
            },
            'buffer_label' => rtrim(rtrim($detail['buffer_percent'], '0'), '.'),
            'outstanding_label' => implode(' · ', $outstandingParts),
            'pending_label' => $pending === null ? null
                : $pending['addresses'].' deposit address'.($pending['addresses'] > 1 ? 'es' : '').' still to sweep',
        ]);
    }
}
