<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\LegalPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Legal Page'])]
class LegalPageEditor extends Component
{
    public LegalPage $page;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string')]
    public string $content = '';

    public function mount(string $slug): void
    {
        $this->page = LegalPage::firstOrCreate(
            ['slug' => $slug],
            ['title' => ucfirst($slug), 'content' => ''],
        );

        $this->title = $this->page->title;
        $this->content = $this->page->content ?? '';
    }

    public function save(): void
    {
        $this->validate();

        $this->page->update([
            'title' => $this->title,
            'content' => $this->content,
        ]);

        session()->flash('status', 'Page saved.');
    }

    public function render(): mixed
    {
        return view('livewire.admin.legal-page-editor');
    }
}
