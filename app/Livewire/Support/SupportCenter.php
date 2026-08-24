<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use App\Models\Faq;
use App\Models\SupportTicket;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Support'])]
class SupportCenter extends Component
{
    #[Url]
    public string $tab = 'faqs';

    public function render(): mixed
    {
        return view('livewire.support.support-center', [
            'faqs' => Faq::orderBy('position')->orderBy('id')->get(),
            'tickets' => $this->tickets(),
        ]);
    }

    /** @return Collection<int, SupportTicket> */
    private function tickets(): Collection
    {
        return auth()->user()->supportTickets()->with(['latestMessage.user'])->withCount('messages')->latest('last_message_at')->get();
    }
}
