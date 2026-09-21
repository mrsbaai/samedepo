<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Admin Website Owners'])]
class WebsiteOwners extends Component
{
    use WithPagination;

    private const SORTABLE = [
        'created_at',
        'email',
        'customers_count',
        'earned_usd',
        'balance_usd',
        'status',
    ];

    public string $uiState = 'normal';

    public string $search = '';

    public string $sort = 'balance_usd';

    public string $direction = 'desc';

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    #[Computed]
    public function ownersQuery()
    {
        $query = User::query()
            ->where('role', 'owner')
            ->where('is_admin', false)
            ->select('users.*')
            ->selectRaw("COALESCE((SELECT SUM(d.usd_value) FROM deposits d WHERE d.user_id = users.id AND d.status = 'credited'), 0) AS earned_usd")
            ->selectRaw('COALESCE((SELECT SUM(b.amount * COALESCE((SELECT uv.conversion_value FROM usd_valuations uv WHERE uv.network = b.network ORDER BY uv.id DESC LIMIT 1), 0)) FROM balances b WHERE b.user_id = users.id), 0) AS balance_usd')
            ->withCount(['customers' => fn ($query) => $query->withoutGlobalScope('owner')]);

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function ($q) use ($term): void {
                $q->where('email', 'like', "%{$term}%");
                if (is_numeric($term)) {
                    $q->orWhere('id', $term);
                }
            });
        }

        return $query->orderBy($this->sortColumn(), $this->direction);
    }

    private function sortColumn(): string
    {
        return $this->sort === 'status' ? 'is_active' : $this->sort;
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = $column === 'email' ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    public function render(): mixed
    {
        return view('livewire.admin.website-owners', [
            'owners' => $this->uiState === 'normal' ? $this->ownersQuery->paginate(10) : collect(),
        ]);
    }
}
