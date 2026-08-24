<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Models\SecurityEvent;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.authentication.layout', ['title' => 'Security history', 'description' => 'Review recent security activity on your account.'])]
class SecurityHistory extends Component
{
    use WithPagination;

    public function render(): mixed
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.authentication.security-history', [
            'events' => SecurityEvent::query()
                ->where('user_id', $user->id)
                ->select(['id', 'event_type', 'ip_address', 'user_agent', 'device', 'location', 'occurred_at'])
                ->latest('occurred_at')
                ->paginate(15),
        ]);
    }
}
