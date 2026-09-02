<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Builder;

final class OwnerFinanceCalculator
{
    private const NETWORKS = ['bitcoin', 'usdt_trc20', 'usdt_erc20'];

    private const NATIVE_KEY = [
        'bitcoin' => 'bitcoin',
        'usdt_trc20' => 'native_trx',
        'usdt_erc20' => 'native_eth',
    ];

    public function __construct(private FeeConverter $feeConverter = new FeeConverter) {}

    public function summary(User $owner): array
    {
        $rates = UsdValuation::query()
            ->whereIn('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20', 'native_trx', 'native_eth'])
            ->pluck('conversion_value', 'network');

        $ratesAvailable = collect(['bitcoin', 'usdt_trc20', 'usdt_erc20', 'native_trx', 'native_eth'])
            ->every(fn ($k) => isset($rates[$k]) && bccomp((string) $rates[$k], '0', 8) > 0);

        $usd = fn (string $amount, string $key): string => bcmul($amount, (string) ($rates[$key] ?? '0'), 8);

        $networks = [];
        foreach (self::NETWORKS as $network) {
            $depositVolume = $this->sum(
                Deposit::withoutGlobalScope('owner')
                    ->where('user_id', $owner->id)
                    ->where('network', $network)
                    ->where('status', 'credited')
                    ->sum('gross_amount')
            );

            $withdrawn = $this->sum(
                Withdrawal::withoutGlobalScope('owner')
                    ->where('user_id', $owner->id)
                    ->where('network', $network)
                    ->where('status', 'sent')
                    ->sum('amount_sent')
            );

            $fee = $this->abs($this->sum(
                LedgerEntry::withoutGlobalScope('owner')
                    ->where('user_id', $owner->id)
                    ->where('network', $network)
                    ->where('reason', 'fee')
                    ->sum('amount')
            ));

            $wdFee = $this->abs($this->sum(
                LedgerEntry::withoutGlobalScope('owner')
                    ->where('user_id', $owner->id)
                    ->where('network', $network)
                    ->where('reason', 'network_fee')
                    ->sum('amount')
            ));

            $sweepGas = $this->feeConverter->sweepGasNative($owner->id, $network);
            $unrecovered = $this->feeConverter->unrecoveredSweepGasNative($owner->id, $network);

            $balance = $this->sum(
                Balance::withoutGlobalScope('owner')
                    ->where('user_id', $owner->id)
                    ->where('network', $network)
                    ->sum('amount')
            );

            $reserved = $this->sum(
                Withdrawal::withoutGlobalScope('owner')
                    ->where('user_id', $owner->id)
                    ->where('network', $network)
                    ->whereIn('status', ['pending', 'approved'])
                    ->sum('gross_amount')
            );

            $revenue = bcadd($fee, $wdFee, 8);
            $owed = bcadd($balance, $reserved, 8);

            $networks[$network] = [
                'deposit_volume' => $depositVolume,
                'deposit_volume_usd' => $usd($depositVolume, $network),
                'withdrawn' => $withdrawn,
                'withdrawn_usd' => $usd($withdrawn, $network),
                'fee_revenue' => $fee,
                'withdrawal_fee_revenue' => $wdFee,
                'revenue_usd' => $usd($revenue, $network),
                'sweep_gas_native' => $sweepGas,
                'sweep_gas_usd' => $usd($sweepGas, self::NATIVE_KEY[$network]),
                'unrecovered_gas_native' => $unrecovered,
                'unrecovered_gas_usd' => $usd($unrecovered, self::NATIVE_KEY[$network]),
                'balance' => $balance,
                'reserved' => $reserved,
                'owed' => $owed,
                'owed_usd' => $usd($owed, $network),
            ];
        }

        $total = fn (string $key) => array_reduce(
            $networks,
            fn (string $carry, array $network) => bcadd($carry, $network[$key], 8),
            '0.00000000'
        );

        $revenueUsd = $total('revenue_usd');
        $gasUsd = $total('sweep_gas_usd');

        return [
            'customers_total' => Customer::withoutGlobalScope('owner')->where('user_id', $owner->id)->count(),
            'customers_new_30d' => Customer::withoutGlobalScope('owner')
                ->where('user_id', $owner->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'deposits_count' => Deposit::withoutGlobalScope('owner')
                ->where('user_id', $owner->id)
                ->where('status', 'credited')
                ->count(),
            'networks' => $networks,
            'totals' => [
                'deposit_volume_usd' => $total('deposit_volume_usd'),
                'withdrawn_usd' => $total('withdrawn_usd'),
                'revenue_usd' => $revenueUsd,
                'sweep_gas_usd' => $gasUsd,
                'unrecovered_gas_usd' => $total('unrecovered_gas_usd'),
                'net_usd' => bcsub($revenueUsd, $gasUsd, 8),
                'owed_usd' => $total('owed_usd'),
            ],
            'rates_available' => $ratesAvailable,
        ];
    }

    public function growth(User $owner, int $months = 12): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);
        $rates = UsdValuation::query()->whereIn('network', self::NETWORKS)->pluck('conversion_value', 'network');

        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $buckets[$month->format('Y-m')] = [
                'month' => $month->format('Y-m-01'),
                'label' => $month->format('M y'),
                'deposits_usd' => 0.0,
                'new_customers' => 0,
            ];
        }

        Deposit::withoutGlobalScope('owner')
            ->where('user_id', $owner->id)
            ->where('status', 'credited')
            ->where('credited_at', '>=', $start)
            ->get(['network', 'gross_amount', 'credited_at'])
            ->each(function (Deposit $deposit) use (&$buckets, $rates): void {
                $key = $deposit->credited_at?->format('Y-m');
                if (! isset($buckets[$key])) {
                    return;
                }

                $buckets[$key]['deposits_usd'] += (float) bcmul(
                    (string) $deposit->gross_amount,
                    (string) ($rates[$deposit->network] ?? '0'),
                    8
                );
            });

        Customer::withoutGlobalScope('owner')
            ->where('user_id', $owner->id)
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->each(function (Customer $customer) use (&$buckets): void {
                $key = $customer->created_at?->format('Y-m');
                if (! isset($buckets[$key])) {
                    return;
                }

                $buckets[$key]['new_customers']++;
            });

        return array_values(array_map(
            fn (array $bucket) => [...$bucket, 'deposits_usd' => round($bucket['deposits_usd'], 2)],
            $buckets
        ));
    }

    public function withdrawals(User $owner): Builder
    {
        return Withdrawal::withoutGlobalScope('owner')
            ->where('user_id', $owner->id)
            ->orderByDesc('created_at');
    }

    private function sum(mixed $value): string
    {
        $normalized = $value === null ? '0.00000000' : (string) $value;

        return bcadd($normalized, '0', 8);
    }

    private function abs(string $value): string
    {
        return bccomp($value, '0', 8) < 0 ? bcsub('0', $value, 8) : $value;
    }
}
