<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Deposit;
use App\Support\Network;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Deposits'])]
class Deposits extends Component
{
    use WithPagination;

    /**
     * The only statuses ever surfaced in this list. `ignored` deposits are
     * never shown here per the input package's design.
     */
    private const VISIBLE_STATUSES = ['detected', 'pending', 'credited'];

    public string $uiState = 'normal';

    public string $statusFilter = 'all';

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load deposits. The deposit service returned an error.";
    }

    #[Computed]
    public function paginatedDeposits(): LengthAwarePaginator
    {
        if ($this->uiState === 'error') {
            return new LengthAwarePaginator([], 0, 10, 1, ['path' => request()->url()]);
        }

        return Deposit::query()
            ->with('customer')
            ->whereIn('status', self::VISIBLE_STATUSES)
            ->when($this->statusFilter !== 'all', function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->orderByDesc('detected_at')
            ->paginate(10)
            ->through(fn (Deposit $deposit) => $this->present($deposit));
    }

    private function present(Deposit $deposit): array
    {
        $meta = Network::exists($deposit->network) ? Network::present($deposit->network) : [
            'slug' => str_replace('_', '-', $deposit->network),
            'label' => $deposit->network,
            'decimals' => 8,
        ];

        $confirmationsRequired = Network::exists($deposit->network) ? Network::confirmations($deposit->network) : 0;
        $statusLabel = $deposit->status === 'pending'
            ? "Pending · {$deposit->confirmation_count}/{$confirmationsRequired} confirmations"
            : ucfirst($deposit->status);

        return [
            'id' => $deposit->id,
            'networkSlug' => $meta['slug'],
            'networkLabel' => $meta['label'],
            'symbol' => $meta['symbol'] ?? '',
            'icon' => $meta['icon'] ?? null,
            'badge' => $meta['badge'] ?? null,
            'amount' => number_format((float) $deposit->gross_amount, $meta['decimals'], '.', ''),
            'status' => $deposit->status,
            'statusLabel' => $statusLabel,
            'confirmationCount' => $deposit->confirmation_count,
            'confirmationsRequired' => $confirmationsRequired,
            'customer' => $deposit->customer,
            'txHash' => $deposit->tx_hash,
            'detectedAt' => ($deposit->detected_at ?? $deposit->created_at)->toIso8601String(),
        ];
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
        return view('livewire.dashboard.deposits');
    }
}
