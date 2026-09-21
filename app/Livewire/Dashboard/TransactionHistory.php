<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Support\Network;
use App\Support\TransactionRows;
use Flux\DateRange;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Transactions'])]
class TransactionHistory extends Component
{
    use WithPagination;

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
        return TransactionRows::STATUS_OPTIONS;
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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->typeFilter = 'all';
        $this->networkFilter = 'all';
        $this->statusFilter = 'all';
        $this->range = null;
        $this->resetPage();
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

        return TransactionRows::for(Auth::id())->entries(
            $this->typeFilter,
            $this->networkFilter,
            $this->statusFilter,
            $this->search,
            $this->range,
        );
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
