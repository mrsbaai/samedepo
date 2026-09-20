<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Customer;
use App\Models\Deposit;
use App\Support\Network;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Customers'])]
class Customers extends Component
{
    use WithPagination;

    public string $uiState = 'normal';

    public string $search = '';

    public string $sort = 'created_at';

    public string $direction = 'desc';

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

        return "Couldn't load customers. The request to the customer service failed.";
    }

    /**
     * Enabled networks where at least one of this owner's customers has a
     * credited deposit — the only networks worth a column.
     *
     * @return array<string, array>
     */
    #[Computed]
    public function networkColumns(): array
    {
        $networks = Network::presentAll(enabledOnly: true);

        $funded = Deposit::query()
            ->where('status', 'credited')
            ->whereIn('network', array_keys($networks))
            ->distinct()
            ->pluck('network')
            ->all();

        return array_intersect_key($networks, array_flip($funded));
    }

    /**
     * @return array<int, string>
     */
    private function sortableColumns(): array
    {
        return [
            'created_at',
            'customer_reference',
            'total_usd',
            'deposits_count',
            ...array_map(fn (string $key): string => "usd_{$key}", array_keys($this->networkColumns)),
        ];
    }

    public function sort(string $column): void
    {
        if (! in_array($column, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = $column === 'customer_reference' ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    #[Computed]
    public function paginatedCustomers(): LengthAwarePaginator
    {
        if ($this->uiState === 'error') {
            return new LengthAwarePaginator([], 0, 10, 1, ['path' => request()->url()]);
        }

        $query = Customer::query()
            ->when($this->search !== '', function ($query) {
                $query->where('customer_reference', 'like', '%'.addcslashes($this->search, '%_\\').'%');
            })
            ->withCount(['deposits as deposits_count' => fn ($q) => $q->where('status', 'credited')])
            ->withSum(['deposits as total_usd' => fn ($q) => $q->where('status', 'credited')], 'usd_value');

        foreach (array_keys($this->networkColumns) as $key) {
            $query
                ->withSum(["deposits as usd_{$key}" => fn ($q) => $q->where('status', 'credited')->where('network', $key)], 'usd_value')
                ->withSum(["deposits as crypto_{$key}" => fn ($q) => $q->where('status', 'credited')->where('network', $key)], 'credited_amount');
        }

        return $query
            ->orderBy($this->sort, $this->direction)
            ->paginate(10);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function retry(): void
    {
        $this->uiState = 'normal';
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.customers');
    }
}
