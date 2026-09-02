<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Deposit;

final class DepositRow
{
    public const NETWORKS = [
        'bitcoin' => ['slug' => 'bitcoin', 'label' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8],
        'usdt_trc20' => ['slug' => 'usdt-trc20', 'label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'decimals' => 2],
        'usdt_erc20' => ['slug' => 'usdt-erc20', 'label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'decimals' => 2],
    ];

    public const STATUS_COLORS = [
        'detected' => 'zinc',
        'pending' => 'amber',
        'credited' => 'green',
        'ignored' => 'zinc',
        'approved' => 'green',
        'denied' => 'zinc',
        'cancelled' => 'zinc',
        'sent' => 'green',
    ];

    public static function present(Deposit $deposit): array
    {
        $meta = self::NETWORKS[$deposit->network] ?? [
            'slug' => str_replace('_', '-', $deposit->network),
            'label' => $deposit->network,
            'symbol' => '',
            'decimals' => 8,
        ];

        $format = fn (?string $value): ?string => $value === null
            ? null
            : number_format((float) $value, $meta['decimals'], '.', '');

        return [
            'id' => $deposit->id,
            'networkSlug' => $meta['slug'],
            'networkLabel' => $meta['label'],
            'symbol' => $meta['symbol'],
            'gross' => $format($deposit->gross_amount),
            'fee' => $deposit->status === 'credited' ? $format($deposit->fee_amount) : null,
            'credited' => $deposit->status === 'credited' ? $format($deposit->credited_amount) : null,
            'status' => $deposit->status,
            'txHash' => $deposit->tx_hash,
            'explorerUrl' => ExplorerUrl::for('tx', $deposit->network, $deposit->tx_hash),
            'at' => $deposit->detected_at ?? $deposit->created_at,
        ];
    }
}
