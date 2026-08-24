<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use App\Models\Announcement;
use Livewire\Component;

class AnnouncementModal extends Component
{
    public bool $show = false;

    public ?Announcement $announcement = null;

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user || $user->is_admin) {
            return;
        }

        $this->announcement = Announcement::current();

        // Shown once per login, on whichever authenticated page the user lands on first —
        // the "seen" flag is cleared again on every fresh sign-in (see ResetAnnouncementSeen).
        if ($this->announcement && ! session('announcement_seen')) {
            $this->show = true;
            session(['announcement_seen' => true]);
        }
    }

    public function render(): mixed
    {
        return view('livewire.support.announcement-modal');
    }
}
