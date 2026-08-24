<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Customer;
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

    #[Computed]
    public function paginatedCustomers(): LengthAwarePaginator
    {
        if ($this->uiState === 'error') {
            return new LengthAwarePaginator([], 0, 10, 1, ['path' => request()->url()]);
        }

        return Customer::query()
            ->when($this->search !== '', function ($query) {
                $query->where('customer_reference', 'like', '%'.addcslashes($this->search, '%_\\').'%');
            })
            ->orderByDesc('created_at')
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
