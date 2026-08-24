<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Deposit;
use App\Models\Withdrawal;
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
     * Network metadata keyed by the DB `network` value.
     *
     * `slug` uses dashes (matches asset file names and the filter values
     * used in the input package); the DB column uses underscores.
     */
    private const NETWORKS = [
        'bitcoin' => ['slug' => 'bitcoin', 'label' => 'Bitcoin', 'decimals' => 8],
        'usdt_trc20' => ['slug' => 'usdt-trc20', 'label' => 'USDT (TRC20)', 'decimals' => 2],
        'usdt_erc20' => ['slug' => 'usdt-erc20', 'label' => 'USDT (ERC20)', 'decimals' => 2],
    ];

    /**
     * Status filter options surfaced across both deposit and withdrawal
     * rows. Unlike the operational Deposits view (which hides `ignored`
     * deposits), this is the full ledger, so every real status is visible.
     */
    private const STATUS_OPTIONS = [
        'detected' => 'Detected',
        'pending' => 'Pending',
        'credited' => 'Credited',
        'ignored' => 'Ignored',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'cancelled' => 'Cancelled',
        'sent' => 'Sent',
    ];

    public string $uiState = 'normal';

    public string $typeFilter = 'all';

    public string $networkFilter = 'all';

    public string $statusFilter = 'all';

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
     * Reverse-maps a display network slug (e.g. `usdt-trc20`) back to its
     * DB column value (e.g. `usdt_trc20`).
     */
    private function dbNetworkFor(string $slug): ?string
    {
        foreach (self::NETWORKS as $dbValue => $meta) {
            if ($meta['slug'] === $slug) {
                return $dbValue;
            }
        }

        return null;
    }

    private function networkMeta(string $dbNetwork): array
    {
        return self::NETWORKS[$dbNetwork] ?? [
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

    private function presentDeposit(Deposit $deposit): array
    {
        $meta = $this->networkMeta($deposit->network);

        $fee = $this->formatAmount($deposit->fee_amount, $meta['decimals']);
        $net = $deposit->credited_amount !== null
            ? $this->formatAmount($deposit->credited_amount, $meta['decimals'])
            : ($fee !== null
                ? $this->formatAmount((string) ((float) $deposit->gross_amount - (float) $deposit->fee_amount), $meta['decimals'])
                : null);

        return [
            'id' => 'deposit-'.$deposit->id,
            'type' => 'deposit',
            'timestamp' => ($deposit->detected_at ?? $deposit->created_at)->toIso8601String(),
            'networkSlug' => $meta['slug'],
            'networkLabel' => $meta['label'],
            'decimals' => $meta['decimals'],
            'gross' => $this->formatAmount((string) $deposit->gross_amount, $meta['decimals']),
            'fee' => $fee,
            'net' => $net,
            'status' => $deposit->status,
            'userRef' => $deposit->customer?->customer_reference,
            'customer' => $deposit->customer,
            'txHash' => $deposit->tx_hash,
        ];
    }

    private function presentWithdrawal(Withdrawal $withdrawal): array
    {
        $meta = $this->networkMeta($withdrawal->network);

        $fee = $this->formatAmount($withdrawal->network_fee, $meta['decimals']);
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
            'decimals' => $meta['decimals'],
            'gross' => $this->formatAmount((string) $withdrawal->gross_amount, $meta['decimals']),
            'fee' => $fee,
            'net' => $net,
            'status' => $withdrawal->status,
            'userRef' => 'Owner',
            'customer' => null,
            'txHash' => $withdrawal->tx_hash,
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

        $entries = [];

        if ($this->typeFilter !== 'withdrawal') {
            $deposits = Deposit::query()
                ->with('customer')
                ->when($dbNetwork !== null, fn ($query) => $query->where('network', $dbNetwork))
                ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
                ->get();

            foreach ($deposits as $deposit) {
                $entries[] = $this->presentDeposit($deposit);
            }
        }

        if ($this->typeFilter !== 'deposit') {
            $withdrawals = Withdrawal::query()
                ->when($dbNetwork !== null, fn ($query) => $query->where('network', $dbNetwork))
                ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
                ->get();

            foreach ($withdrawals as $withdrawal) {
                $entries[] = $this->presentWithdrawal($withdrawal);
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
