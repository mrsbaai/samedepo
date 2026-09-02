<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Balance;
use App\Models\Deposit;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\Withdrawal;

final class TreasuryProfitCalculator
{
    public function forNetwork(string $network): array
    {
        $available = $this->sum(TreasuryWallet::query()->where('network', $network)->value('available_funds'));
        $unswept = $this->sum(Deposit::query()->withoutGlobalScope('owner')->where('network', $network)->where('status', 'credited')->whereNull('swept_at')->sum('gross_amount'));
        $ownerBalances = $this->sum(Balance::query()->withoutGlobalScope('owner')->where('network', $network)->sum('amount'));
        $reservedWithdrawals = $this->sum(Withdrawal::query()->withoutGlobalScope('owner')->where('network', $network)->whereIn('status', ['pending', 'approved'])->sum('gross_amount'));
        $paidOut = $this->sum(TreasuryPayout::query()->where('network', $network)->whereIn('status', ['sent', 'confirmed'])->sum('amount'));
        $liabilities = bcadd($ownerBalances, $reservedWithdrawals, 8);
        $assets = bcadd($available, $unswept, 8);
        $equity = bcsub($assets, $liabilities, 8);
        $spendable = bcsub($available, $reservedWithdrawals, 8);
        $withdrawable = bccomp($equity, $spendable, 8) < 0 ? $equity : $spendable;

        if (bccomp($withdrawable, '0', 8) < 0) {
            $withdrawable = '0.00000000';
        }

        return [
            'available' => $available,
            'unswept' => $unswept,
            'owner_balances' => $ownerBalances,
            'reserved_withdrawals' => $reservedWithdrawals,
            'liabilities' => $liabilities,
            'assets' => $assets,
            'equity' => $equity,
            'spendable' => $spendable,
            'withdrawable' => $withdrawable,
            'paid_out' => $paidOut,
            'withdrawable_usd' => $this->usd($withdrawable, $network),
            'equity_usd' => $this->usd($equity, $network),
            'unswept_usd' => $this->usd($unswept, $network),
        ];
    }

    public function summary(): array
    {
        $networks = [];
        $totalWithdrawableUsd = '0.00000000';
        $totalEquityUsd = '0.00000000';
        $hasDeficit = false;

        foreach (['bitcoin', 'usdt_trc20', 'usdt_erc20'] as $network) {
            $networks[$network] = $this->forNetwork($network);
            $totalWithdrawableUsd = bcadd($totalWithdrawableUsd, $networks[$network]['withdrawable_usd'], 8);
            $totalEquityUsd = bcadd($totalEquityUsd, $networks[$network]['equity_usd'], 8);
            $hasDeficit = $hasDeficit || bccomp($networks[$network]['equity'], '0', 8) < 0;
        }

        return [
            'networks' => $networks,
            'total_withdrawable_usd' => $totalWithdrawableUsd,
            'total_equity_usd' => $totalEquityUsd,
            'has_deficit' => $hasDeficit,
        ];
    }

    private function sum(mixed $value): string
    {
        return number_format((float) ($value ?? '0.00000000'), 8, '.', '');
    }

    private function usd(string $amount, string $valuationKey): string
    {
        $value = (string) (UsdValuation::query()->where('network', $valuationKey)->value('conversion_value') ?? '0');

        return bcmul($amount, $value, 8);
    }
}
