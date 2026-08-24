<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Announcement;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Announcement'])]
class AnnouncementEditor extends Component
{
    #[Validate('nullable|string')]
    public string $content = '';

    public function mount(): void
    {
        $this->content = Announcement::query()->first()?->content ?? '';
    }

    public function save(): void
    {
        $this->validate();

        Announcement::query()->firstOrCreate([])->update(['content' => $this->content]);

        session()->flash('status', 'Announcement saved. It is now shown to signed-in users.');
    }

    public function remove(): void
    {
        $this->content = '';

        Announcement::query()->firstOrCreate([])->update(['content' => null]);

        session()->flash('status', 'Announcement removed.');
    }

    public function render(): mixed
    {
        return view('livewire.admin.announcement-editor');
    }
}
