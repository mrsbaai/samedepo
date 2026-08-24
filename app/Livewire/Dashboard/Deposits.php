<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Deposit;
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
     * Network metadata keyed by the DB `network` value.
     *
     * `slug` uses dashes (matches asset file names); the DB column uses
     * underscores.
     */
    private const NETWORKS = [
        'bitcoin' => ['slug' => 'bitcoin', 'label' => 'Bitcoin', 'decimals' => 8],
        'usdt_trc20' => ['slug' => 'usdt-trc20', 'label' => 'USDT (TRC20)', 'decimals' => 2],
        'usdt_erc20' => ['slug' => 'usdt-erc20', 'label' => 'USDT (ERC20)', 'decimals' => 2],
    ];

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
        $meta = self::NETWORKS[$deposit->network] ?? [
            'slug' => str_replace('_', '-', $deposit->network),
            'label' => $deposit->network,
            'decimals' => 8,
        ];

        return [
            'id' => $deposit->id,
            'networkSlug' => $meta['slug'],
            'networkLabel' => $meta['label'],
            'amount' => number_format((float) $deposit->gross_amount, $meta['decimals'], '.', ''),
            'status' => $deposit->status,
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
