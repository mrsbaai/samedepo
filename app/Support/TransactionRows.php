<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use Flux\DateRange;

final class TransactionRows
{
    /**
     * Status filter options surfaced across both deposit and withdrawal
     * rows. Unlike the operational Deposits view (which hides `ignored`
     * deposits), this is the full ledger, so every real status is visible.
     */
    public const STATUS_OPTIONS = [
        'detected' => 'Detected',
        'pending' => 'Pending',
        'credited' => 'Credited',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'cancelled' => 'Cancelled',
        'sent' => 'Sent',
    ];

    private function __construct(
        private readonly ?int $ownerId,
        private readonly array $usdRates,
    ) {}

    public static function for(?int $ownerId): self
    {
        return new self(
            $ownerId,
            UsdValuation::query()->pluck('conversion_value', 'network')->all(),
        );
    }

    /**
     * @return array<int, array>
     */
    public function entries(
        string $typeFilter,
        string $networkSlug,
        string $statusFilter,
        string $search,
        ?DateRange $range,
    ): array {
        $dbNetwork = $networkSlug !== 'all' ? $this->dbNetworkFor($networkSlug) : null;
        $like = $search !== '' ? '%'.addcslashes($search, '%_\\').'%' : null;
        [$from, $to] = $range?->hasStart() && $range->hasEnd()
            ? [$range->start()->startOfDay(), $range->end()->endOfDay()]
            : [null, null];

        $entries = [];

        if (in_array($typeFilter, ['all', 'deposit'], true)) {
            $deposits = Deposit::query()
                ->withoutGlobalScope('owner')
                ->when($this->ownerId !== null, fn ($query) => $query->where('user_id', $this->ownerId))
                ->with(['customer' => fn ($query) => $query->withoutGlobalScope('owner'), 'user'])
                ->where('status', '!=', 'ignored')
                ->when($dbNetwork !== null, fn ($query) => $query->where('network', $dbNetwork))
                ->when($statusFilter !== 'all', fn ($query) => $query->where('status', $statusFilter))
                ->when($like !== null, fn ($query) => $query->where(function ($q) use ($like) {
                    $q->where('tx_hash', 'like', $like)
                        ->orWhereHas('customer', fn ($c) => $c->withoutGlobalScope('owner')->where('customer_reference', 'like', $like))
                        ->when($this->ownerId === null, fn ($q2) => $q2->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)));
                }))
                ->when($from !== null, fn ($query) => $query->whereBetween('detected_at', [$from, $to]))
                ->get();

            foreach ($deposits as $deposit) {
                $entries[] = $this->presentDeposit($deposit);
            }
        }

        if (in_array($typeFilter, ['all', 'withdrawal'], true)) {
            $withdrawals = Withdrawal::query()
                ->withoutGlobalScope('owner')
                ->when($this->ownerId !== null, fn ($query) => $query->where('user_id', $this->ownerId))
                ->with('user')
                ->when($dbNetwork !== null, fn ($query) => $query->where('network', $dbNetwork))
                ->when($statusFilter !== 'all', fn ($query) => $query->where('status', $statusFilter))
                ->when($like !== null, fn ($query) => $query->where(function ($q) use ($like) {
                    $q->where('tx_hash', 'like', $like)
                        ->when($this->ownerId === null, fn ($q2) => $q2->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)));
                }))
                ->when($from !== null, fn ($query) => $query->whereBetween('created_at', [$from, $to]))
                ->get();

            foreach ($withdrawals as $withdrawal) {
                $entries[] = $this->presentWithdrawal($withdrawal);
            }
        }

        if ($typeFilter === 'all' || $typeFilter === 'adjustment') {
            if ($statusFilter === 'all' || $statusFilter === 'sent') {
                $ledgerEntries = LedgerEntry::query()
                    ->withoutGlobalScope('owner')
                    ->when($this->ownerId !== null, fn ($query) => $query->where('user_id', $this->ownerId))
                    ->with([
                        'user',
                        'withdrawal' => fn ($query) => $query->withoutGlobalScope('owner'),
                        'deposit' => fn ($query) => $query->withoutGlobalScope('owner'),
                    ])
                    ->whereIn('reason', ['network_fee_adjustment', 'consolidation_fee', 'gas_recovery_credit'])
                    ->when($dbNetwork !== null, fn ($query) => $query->where('network', $dbNetwork))
                    ->when($like !== null, fn ($query) => $query->where(function ($q) use ($like) {
                        $q->whereHas('withdrawal', fn ($w) => $w->withoutGlobalScope('owner')->where('tx_hash', 'like', $like))
                            ->orWhereHas('deposit', fn ($d) => $d->withoutGlobalScope('owner')->where('tx_hash', 'like', $like))
                            ->when($this->ownerId === null, fn ($q2) => $q2->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)));
                    }))
                    ->when($from !== null, fn ($query) => $query->whereBetween('created_at', [$from, $to]))
                    ->get();

                foreach ($ledgerEntries as $entry) {
                    $entries[] = $this->presentLedgerEntry($entry);
                }
            }
        }

        usort($entries, fn ($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));

        return $entries;
    }

    private function dbNetworkFor(string $slug): ?string
    {
        foreach (Network::keys() as $dbValue) {
            if (Network::present($dbValue)['slug'] === $slug) {
                return $dbValue;
            }
        }

        return null;
    }

    private function networkMeta(string $dbNetwork): array
    {
        return Network::exists($dbNetwork) ? Network::present($dbNetwork) : [
            'slug' => str_replace('_', '-', $dbNetwork),
            'label' => $dbNetwork,
            'decimals' => 8,
        ];
    }

    private function formatAmount(?string $amount, int $decimals): ?string
    {
        if ($amount === null) {
            return null;
        }

        return number_format((float) $amount, $decimals, '.', '');
    }

    private function usdDisplay(?string $stored, string $network, ?string $amount): ?string
    {
        if ($stored !== null) {
            return '$'.number_format((float) $stored, 2);
        }

        if ($amount === null) {
            return null;
        }

        $rate = $this->usdRates[$network] ?? null;

        if ($rate === null) {
            return null;
        }

        return '≈$'.number_format((float) bcmul($amount, (string) $rate, 8), 2);
    }

    private function txFields(string $network, ?string $txHash): array
    {
        return [
            'txHash' => $txHash,
            'txShort' => $txHash !== null ? substr($txHash, 0, 6).'…'.substr($txHash, -4) : null,
            'explorerUrl' => ExplorerUrl::for('tx', $network, $txHash),
        ];
    }

    private function presentDeposit(Deposit $deposit): array
    {
        $meta = $this->networkMeta($deposit->network);

        $fee = $this->formatAmount($deposit->fee_amount, $meta['decimals']);
        $net = $deposit->credited_amount !== null
            ? $this->formatAmount($deposit->credited_amount, $meta['decimals'])
            : ($fee !== null
                ? $this->formatAmount((string) ((float) $deposit->gross_amount - (float) $deposit->fee_amount), $meta['decimals'])
                : null);

        $confirmationsRequired = Network::exists($deposit->network) ? Network::confirmations($deposit->network) : 0;
        $statusLabel = $deposit->status === 'pending'
            ? "Pending · {$deposit->confirmation_count}/{$confirmationsRequired} confirmations"
            : ucfirst($deposit->status);

        return [
            'id' => 'deposit-'.$deposit->id,
            'type' => 'deposit',
            'timestamp' => ($deposit->detected_at ?? $deposit->created_at)->toIso8601String(),
            'networkSlug' => $meta['slug'],
            'networkLabel' => $meta['label'],
            'symbol' => $meta['symbol'] ?? '',
            'icon' => $meta['icon'] ?? null,
            'badge' => $meta['badge'] ?? null,
            'decimals' => $meta['decimals'],
            'gross' => $this->formatAmount((string) $deposit->gross_amount, $meta['decimals']),
            'fee' => $fee,
            'net' => $net,
            'status' => $deposit->status,
            'statusLabel' => $statusLabel,
            'confirmationCount' => $deposit->confirmation_count,
            'confirmationsRequired' => $confirmationsRequired,
            'userRef' => $deposit->customer?->customer_reference,
            'customer' => $deposit->customer,
            'ownerId' => $deposit->user_id,
            'ownerEmail' => $deposit->user?->email,
            'usd' => $this->usdDisplay(
                $deposit->usd_value === null ? null : (string) $deposit->usd_value,
                $deposit->network,
                $deposit->credited_amount === null ? (string) $deposit->gross_amount : (string) $deposit->credited_amount,
            ),
            ...$this->txFields($deposit->network, $deposit->tx_hash),
        ];
    }

    private function presentWithdrawal(Withdrawal $withdrawal): array
    {
        $meta = $this->networkMeta($withdrawal->network);

        $consolidationFee = $withdrawal->consolidation_fee !== null
            && bccomp((string) $withdrawal->consolidation_fee, '0', 8) > 0
            ? $this->formatAmount((string) $withdrawal->consolidation_fee, $meta['decimals'])
            : null;
        $fee = $this->formatAmount(
            $withdrawal->network_fee === null
                ? $withdrawal->consolidation_fee
                : bcadd((string) $withdrawal->network_fee, (string) ($withdrawal->consolidation_fee ?? '0'), 8),
            $meta['decimals'],
        );
        $net = $withdrawal->amount_sent !== null
            ? $this->formatAmount($withdrawal->amount_sent, $meta['decimals'])
            : ($fee !== null
                ? $this->formatAmount((string) ((float) $withdrawal->gross_amount - (float) $withdrawal->network_fee), $meta['decimals'])
                : null);

        return [
            'id' => 'withdrawal-'.$withdrawal->id,
            'type' => 'withdrawal',
            'timestamp' => $withdrawal->created_at->toIso8601String(),
            'networkSlug' => $meta['slug'],
            'networkLabel' => $meta['label'],
            'symbol' => $meta['symbol'] ?? '',
            'icon' => $meta['icon'] ?? null,
            'badge' => $meta['badge'] ?? null,
            'decimals' => $meta['decimals'],
            'gross' => $this->formatAmount((string) $withdrawal->gross_amount, $meta['decimals']),
            'fee' => $fee,
            'networkFee' => $withdrawal->network_fee !== null
                ? $this->formatAmount((string) $withdrawal->network_fee, $meta['decimals'])
                : null,
            'consolidationFee' => $consolidationFee,
            'net' => $net,
            'status' => $withdrawal->status,
            'statusLabel' => ucfirst($withdrawal->status),
            'userRef' => 'Owner',
            'customer' => null,
            'ownerId' => $withdrawal->user_id,
            'ownerEmail' => $withdrawal->user?->email,
            'usd' => $this->usdDisplay(
                $withdrawal->usd_value === null ? null : (string) $withdrawal->usd_value,
                $withdrawal->network,
                $withdrawal->amount_sent === null ? (string) $withdrawal->gross_amount : (string) $withdrawal->amount_sent,
            ),
            ...$this->txFields($withdrawal->network, $withdrawal->tx_hash),
        ];
    }

    private function presentLedgerEntry(LedgerEntry $entry): array
    {
        $meta = $this->networkMeta($entry->network);

        $label = match ($entry->reason) {
            'network_fee_adjustment' => bccomp((string) $entry->amount, '0', 8) < 0 ? 'Gas overage' : 'Gas refund',
            'consolidation_fee' => 'Consolidation fee',
            'gas_recovery_credit' => 'Gas recovery credit',
            default => 'Adjustment',
        };

        return [
            'id' => 'ledger-'.$entry->id,
            'type' => 'adjustment',
            'timestamp' => $entry->created_at->toIso8601String(),
            'networkSlug' => $meta['slug'],
            'networkLabel' => $meta['label'],
            'symbol' => $meta['symbol'] ?? '',
            'icon' => $meta['icon'] ?? null,
            'badge' => $meta['badge'] ?? null,
            'decimals' => $meta['decimals'],
            'gross' => $this->formatAmount('0.00000000', $meta['decimals']),
            'fee' => null,
            'net' => $this->formatAmount((string) $entry->amount, $meta['decimals']),
            'status' => 'sent',
            'statusLabel' => $label,
            'userRef' => $label,
            'customer' => null,
            'ownerId' => $entry->user_id,
            'ownerEmail' => $entry->user?->email,
            'usd' => null,
            ...$this->txFields($entry->network, $entry->withdrawal?->tx_hash ?? $entry->deposit?->tx_hash),
        ];
    }
}
