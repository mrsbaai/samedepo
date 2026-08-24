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

    public string $uiState = 'normal';

    public string $search = '';

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
            ->orderBy('id', 'desc');

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function ($q) use ($term): void {
                $q->where('email', 'like', "%{$term}%");
                if (is_numeric($term)) {
                    $q->orWhere('id', $term);
                }
            });
        }

        return $query;
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
