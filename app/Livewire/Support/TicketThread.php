<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use App\Actions\Support\SuggestReply;
use App\Livewire\Support\Concerns\SendsTicketMessages;
use App\Models\SupportIdentity;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.dashboard.layout', ['title' => 'Support Ticket'])]
class TicketThread extends Component
{
    use SendsTicketMessages;
    use WithFileUploads;

    public SupportTicket $ticket;

    #[Validate('required|string')]
    public string $body = '';

    #[Validate('nullable|image|max:5120')]
    public $image = null;

    #[Validate('required|string|in:support,sales,management,administration')]
    public string $identityRole = 'support';

    public ?int $editingMessageId = null;

    public string $editBody = '';

    public function mount(SupportTicket $ticket): void
    {
        $user = auth()->user();

        abort_unless($user?->is_admin || $user?->id === $ticket->user_id, 403);

        $this->ticket = $ticket;
        $ticket->markReadFor($user);
    }

    public function reply(): void
    {
        $this->validate();

        $this->postMessage($this->ticket, $this->body, $this->image, $this->identityRole);

        $this->reset('body', 'image');
        $this->ticket->refresh();
        $this->ticket->markReadFor(auth()->user());
    }

    public function suggestReply(): void
    {
        if (! auth()->user()?->is_admin) {
            return;
        }

        $suggestion = app(SuggestReply::class)->suggest($this->ticket, $this->body);

        if ($suggestion === null) {
            $this->addError('body', 'Could not generate a reply. Please check the OpenRouter configuration and try again.');

            return;
        }

        $this->body = $suggestion;
        $this->resetValidation();
    }

    public function startEditing(int $messageId): void
    {
        $message = $this->resolveEditableMessage($messageId);

        if (! $message) {
            return;
        }

        $this->editingMessageId = $message->id;
        $this->editBody = $message->body;
    }

    public function cancelEditing(): void
    {
        $this->reset('editingMessageId', 'editBody');
    }

    public function saveEdit(): void
    {
        $this->validate(['editBody' => 'required|string']);

        $message = $this->resolveEditableMessage($this->editingMessageId);

        if (! $message) {
            $this->reset('editingMessageId', 'editBody');

            return;
        }

        $message->update(['body' => $this->editBody]);
        $this->reset('editingMessageId', 'editBody');
    }

    public function deleteMessage(int $messageId): void
    {
        $message = $this->resolveEditableMessage($messageId);

        if (! $message) {
            return;
        }

        if ($message->image_path) {
            Storage::disk('public')->delete($message->image_path);
        }

        $message->delete();
        $this->ticket->refresh();
    }

    public function toggleStatus(): void
    {
        // Users can close their own ticket, but only admins may reopen a closed one —
        // reopening always starts a fresh conversation with a new ticket instead.
        if (! $this->ticket->isOpen() && ! auth()->user()->is_admin) {
            return;
        }

        $this->ticket->update([
            'status' => $this->ticket->isOpen() ? SupportTicket::STATUS_CLOSED : SupportTicket::STATUS_OPEN,
        ]);
    }

    public function render(): mixed
    {
        return view('livewire.support.ticket-thread', [
            'messages' => $this->ticket->messages()->with('user')->get(),
            'identities' => auth()->user()?->is_admin ? SupportIdentity::query()->whereIn('role', SupportIdentity::ROLES)->get() : collect(),
        ]);
    }

    private function resolveEditableMessage(?int $messageId): ?SupportTicketMessage
    {
        $user = auth()->user();

        if (! $user?->is_admin || ! $messageId) {
            return null;
        }

        $message = $this->ticket->messages()->where('id', $messageId)->first();

        if (! $message || $message->user_id !== $user->id || $message->isRead()) {
            return null;
        }

        return $message;
    }
}
