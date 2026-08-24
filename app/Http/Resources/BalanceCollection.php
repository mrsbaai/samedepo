<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UsdValuation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BalanceCollection extends ResourceCollection
{
    public const NETWORKS = [
        'bitcoin' => 'Bitcoin',
        'usdt_trc20' => 'USDT (TRC20)',
        'usdt_erc20' => 'USDT (ERC20)',
    ];

    public function toArray(Request $request): array
    {
        $valuationByNetwork = UsdValuation::query()
            ->whereIn('network', array_keys(self::NETWORKS))
            ->get()
            ->keyBy('network');

        $balanceByNetwork = $this->collection->keyBy('network');

        $items = [];
        $totalUsd = 0.0;

        foreach (self::NETWORKS as $key => $label) {
            $balance = $balanceByNetwork->get($key);
            $amount = $balance !== null ? (float) $balance->amount : 0.0;
            $conversion = (float) ($valuationByNetwork->get($key)?->conversion_value ?? 0);
            $usdValue = $amount * $conversion;
            $totalUsd += $usdValue;

            $items[] = [
                'network' => $label,
                'amount' => $amount,
                'usd_value' => round($usdValue, 2),
            ];
        }

        return [
            'balances' => $items,
            'total_usd' => round($totalUsd, 2),
            'last_updated_at' => $valuationByNetwork->max('updated_at')?->toIso8601String(),
        ];
    }
}
