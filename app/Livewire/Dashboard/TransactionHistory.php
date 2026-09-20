<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Support\Network;
use Flux\DateRange;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Transaction History'])]
class TransactionHistory extends Component
{
    use WithPagination;

    /**
     * Status filter options surfaced across both deposit and withdrawal
     * rows. Unlike the operational Deposits view (which hides `ignored`
     * deposits), this is the full ledger, so every real status is visible.
     */
    private const STATUS_OPTIONS = [
        'detected' => 'Detected',
        'pending' => 'Pending',
        'credited' => 'Credited',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'cancelled' => 'Cancelled',
        'sent' => 'Sent',
    ];

    public string $uiState = 'normal';

    public string $search = '';

    public string $typeFilter = 'all';

    public string $networkFilter = 'all';

    public string $statusFilter = 'all';

    public ?DateRange $range = null;

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    #[Computed]
    public function statusOptions(): array
    {
        return self::STATUS_OPTIONS;
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load transaction history. The ledger service returned an error.";
    }

    /**
     * Reverse-maps a display network slug (e.g. `litecoin`) back to its
     * DB column value (e.g. `litecoin`).
     */
    #[Computed]
    public function networkOptions(): array
    {
        return collect(Network::presentAll(enabledOnly: true))
            ->mapWithKeys(fn (array $meta): array => [$meta['slug'] => $meta['label']])
            ->all();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->typeFilter !== 'all'
            || $this->networkFilter !== 'all'
            || $this->statusFilter !== 'all'
            || ($this->range?->hasStart() ?? false);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function usdRates(): array
    {
        return UsdValuation::query()->pluck('conversion_value', 'network')->all();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->typeFilter = 'all';
        $this->networkFilter = 'all';
        $this->statusFilter = 'all';
        $this->range = null;
        $this->resetPage();
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
            'txHash' => $deposit->tx_hash,
            'usd' => $this->usdDisplay(
                $deposit->usd_value === null ? null : (string) $deposit->usd_value,
                $deposit->network,
                $deposit->credited_amount === null ? (string) $deposit->gross_amount : (string) $deposit->credited_amount,
            ),
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
            'txHash' => $withdrawal->tx_hash,
            'usd' => $this->usdDisplay(
                $withdrawal->usd_value === null ? null : (string) $withdrawal->usd_value,
                $withdrawal->network,
                $withdrawal->amount_sent === null ? (string) $withdrawal->gross_amount : (string) $withdrawal->amount_sent,
            ),
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
            'txHash' => $entry->withdrawal?->tx_hash ?? $entry->deposit?->tx_hash,
            'usd' => null,
        ];
    }

    /**
     * @return array<int, array>
     */
    #[Computed]
    public function entries(): array
    {
        if ($this->uiState === 'error') {
            return [];
        }

        $dbNetwork = $this->networkFilter !== 'all' ? $this->dbNetworkFor($this->networkFilter) : null;
        $search = $this->search !== '' ? '%'.addcslashes($this->search, '%_\\').'%' : null;
        [$from, $to] = $this->range?->hasStart() && $this->range->hasEnd()
            ? [$this->range->start()->startOfDay(), $this->range->end()->endOfDay()]
            : [null, null];

        $entries = [];

        if ($this->typeFilter !== 'withdrawal') {
            $deposits = Deposit::query()
                ->with('customer')
                ->where('status', '!=', 'ignored')
                ->when($dbNetwork !== null, fn ($query) => $query->where('network', $dbNetwork))
                ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
                ->when($search !== null, fn ($query) => $query->where(function ($q) use ($search) {
                    $q->where('tx_hash', 'like', $search)
                        ->orWhereHas('customer', fn ($c) => $c->where('customer_reference', 'like', $search));
                }))
                ->when($from !== null, fn ($query) => $query->whereBetween('detected_at', [$from, $to]))
                ->get();

            foreach ($deposits as $deposit) {
                $entries[] = $this->presentDeposit($deposit);
            }
        }

        if ($this->typeFilter !== 'deposit') {
            $withdrawals = Withdrawal::query()
                ->when($dbNetwork !== null, fn ($query) => $query->where('network', $dbNetwork))
                ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
                ->when($search !== null, fn ($query) => $query->where('tx_hash', 'like', $search))
                ->when($from !== null, fn ($query) => $query->whereBetween('created_at', [$from, $to]))
                ->get();

            foreach ($withdrawals as $withdrawal) {
                $entries[] = $this->presentWithdrawal($withdrawal);
            }
        }

        if ($this->typeFilter === 'all' || $this->typeFilter === 'adjustment') {
            if ($this->statusFilter === 'all' || $this->statusFilter === 'sent') {
                $ledgerEntries = LedgerEntry::query()
                    ->whereIn('reason', ['network_fee_adjustment', 'consolidation_fee', 'gas_recovery_credit'])
                    ->when($dbNetwork !== null, fn ($query) => $query->where('network', $dbNetwork))
                    ->when($search !== null, fn ($query) => $query->where(function ($q) use ($search) {
                        $q->whereHas('withdrawal', fn ($w) => $w->where('tx_hash', 'like', $search))
                            ->orWhereHas('deposit', fn ($d) => $d->where('tx_hash', 'like', $search));
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

    #[Computed]
    public function paginatedEntries(): LengthAwarePaginator
    {
        $entries = $this->entries;
        $perPage = 10;
        $page = $this->getPage();

        return new LengthAwarePaginator(
            array_slice($entries, ($page - 1) * $perPage, $perPage),
            count($entries),
            $perPage,
            $page,
            ['path' => request()->url()]
        );
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRange(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedNetworkFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function retry(): void
    {
        $this->uiState = 'normal';
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.transaction-history');
    }
}
