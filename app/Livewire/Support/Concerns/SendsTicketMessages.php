<?php

declare(strict_types=1);

namespace App\Livewire\Support\Concerns;

use App\Models\SupportIdentity;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\Support\NewTicketMessageNotification;
use Illuminate\Http\UploadedFile;

trait SendsTicketMessages
{
    private function postMessage(SupportTicket $ticket, string $body, ?UploadedFile $image = null, ?string $identityRole = null): SupportTicketMessage
    {
        $author = auth()->user();
        $identity = $author->is_admin ? SupportIdentity::forRole($identityRole ?: 'support') : null;

        $message = $ticket->messages()->create([
            'user_id' => $author->id,
            'author_name' => $author->is_admin ? SupportTicketMessage::formatAgentName($identity?->name, $identity?->role) : null,
            'author_avatar' => $author->is_admin ? $identity?->avatarUrl() : null,
            'body' => $body,
            'image_path' => $image?->store('support-tickets', 'public'),
        ]);

        $ticket->update([
            'last_message_at' => now(),
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $this->notifyOtherParty($ticket, $author, $message);

        return $message;
    }

    private function notifyOtherParty(SupportTicket $ticket, User $author, SupportTicketMessage $message): void
    {
        $recipients = $author->is_admin
            ? collect([$ticket->user])
            : User::where('is_admin', true)->get();

        $recipients->each(fn (User $recipient) => $recipient->notify(new NewTicketMessageNotification($message)));
    }
}
