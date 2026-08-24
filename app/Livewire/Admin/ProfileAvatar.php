<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout(false)]
class ProfileAvatar extends Component
{
    #[On('support-avatar-updated')]
    public function refreshAvatar(): void
    {
        // Re-render with the new avatar URL.
    }

    public function render(): mixed
    {
        return view('livewire.admin.profile-avatar', [
            'avatarUrl' => auth()->user()?->avatarUrl(),
        ]);
    }
}
