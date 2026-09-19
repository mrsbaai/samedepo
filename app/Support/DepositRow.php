<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Deposit;

final class DepositRow
{
    /**
     * Registry-driven network metadata keyed by DB network value. Prefer
     * this over the const so new networks appear without code changes.
     */
    public static function networks(): array
    {
        $networks = [];

        foreach (Network::all() as $key => $ignored) {
            $presented = Network::present($key);
            $networks[$key] = [
                'slug' => $presented['slug'],
                'label' => $presented['label'],
                'symbol' => $presented['symbol'],
                'decimals' => $presented['decimals'],
                'icon' => $presented['icon'],
            ];
        }

        return $networks;
    }

    /**
     * Metadata for one network, tolerating values absent from the registry
     * (e.g. historical rows for removed networks).
     */
    public static function meta(string $network): array
    {
        return self::networks()[$network] ?? [
            'slug' => str_replace('_', '-', $network),
            'label' => $network,
            'symbol' => '',
            'decimals' => 8,
            'icon' => 'crypto/'.str_replace('_', '-', $network).'.svg',
        ];
    }

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
        $meta = self::meta($deposit->network);

        $format = fn (?string $value): ?string => $value === null
            ? null
            : number_format((float) $value, $meta['decimals'], '.', '');

        $confirmationsRequired = Network::confirmations($deposit->network);
        $statusLabel = $deposit->status === 'pending'
            ? "Pending · {$deposit->confirmation_count}/{$confirmationsRequired} confirmations"
            : ucfirst($deposit->status);

        return [
            'id' => $deposit->id,
            'networkSlug' => $meta['slug'],
            'networkLabel' => $meta['label'],
            'symbol' => $meta['symbol'],
            'gross' => $format($deposit->gross_amount),
            'fee' => $deposit->status === 'credited' ? $format($deposit->fee_amount) : null,
            'credited' => $deposit->status === 'credited' ? $format($deposit->credited_amount) : null,
            'status' => $deposit->status,
            'statusLabel' => $statusLabel,
            'confirmationCount' => $deposit->confirmation_count,
            'confirmationsRequired' => $confirmationsRequired,
            'txHash' => $deposit->tx_hash,
            'explorerUrl' => ExplorerUrl::for('tx', $deposit->network, $deposit->tx_hash),
            'at' => $deposit->detected_at ?? $deposit->created_at,
        ];
    }
}
