<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'User Search'])]
class UserSearch extends Component
{
    public string $query = '';

    public function render(): mixed
    {
        return view('livewire.admin.user-search', [
            'users' => $this->users(),
        ]);
    }

    /** @return Collection<int, User> */
    private function users(): Collection
    {
        return User::query()
            ->when($this->query, function ($query, string $search): void {
                $query->where('email', 'like', '%'.$search.'%');
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }
}
