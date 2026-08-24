<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAutoClosedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly SupportTicket $ticket,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ticket closed due to inactivity: '.$this->ticket->subject)
            ->greeting('Hello,')
            ->line('We closed your support ticket "'.$this->ticket->subject.'" after '.config('support.auto_close_after_days').' days of inactivity.')
            ->line('If you still need help, just reopen the ticket and reply.')
            ->action('View ticket', route('support.tickets.show', $this->ticket));
    }
}
