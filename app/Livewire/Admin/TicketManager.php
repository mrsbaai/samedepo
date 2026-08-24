<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\SupportTicket;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Support Tickets'])]
class TicketManager extends Component
{
    public function render(): mixed
    {
        return view('livewire.admin.ticket-manager', [
            'tickets' => $this->tickets(),
        ]);
    }

    public function closeTicket(int $id): void
    {
        SupportTicket::findOrFail($id)->update(['status' => SupportTicket::STATUS_CLOSED]);
    }

    /** @return Collection<int, SupportTicket> */
    private function tickets(): Collection
    {
        return SupportTicket::query()
            ->where('status', SupportTicket::STATUS_OPEN)
            ->with(['user', 'latestMessage.user'])
            ->get()
            ->sortByDesc(fn (SupportTicket $ticket) => [
                $ticket->latestMessage?->user?->is_admin ? 0 : 1,
                $ticket->last_message_at,
            ])
            ->values();
    }
}
