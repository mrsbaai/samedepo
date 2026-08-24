<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use App\Livewire\Support\Concerns\SendsTicketMessages;
use App\Models\Faq;
use App\Models\SupportTicket;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.dashboard.layout', ['title' => 'New Ticket'])]
class TicketCreate extends Component
{
    use SendsTicketMessages;
    use WithFileUploads;

    #[Validate('required|string|max:255')]
    public string $subject = '';

    #[Validate('required|string')]
    public string $body = '';

    #[Validate('nullable|image|max:5120')]
    public $image = null;

    public function mount(): void
    {
        $openTicket = auth()->user()->supportTickets()->where('status', SupportTicket::STATUS_OPEN)->first();

        if ($openTicket) {
            $this->redirectRoute('support.tickets.show', $openTicket, navigate: true);
        }
    }

    public function create(): mixed
    {
        $this->validate();

        if (auth()->user()->supportTickets()->where('status', SupportTicket::STATUS_OPEN)->exists()) {
            $this->addError('subject', 'You already have an open ticket. Please use that one instead.');

            return null;
        }

        $ticket = auth()->user()->supportTickets()->create([
            'subject' => $this->subject,
            'status' => SupportTicket::STATUS_OPEN,
            'last_message_at' => now(),
        ]);

        $this->postMessage($ticket, $this->body, $this->image);

        return $this->redirectRoute('support', ['tab' => 'tickets'], navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.support.ticket-create', [
            'faqs' => Faq::orderBy('position')->orderBy('id')->get(),
        ]);
    }
}
