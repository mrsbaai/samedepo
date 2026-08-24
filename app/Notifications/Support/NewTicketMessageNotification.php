<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewTicketMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly SupportTicketMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->message->ticket;
        $isFromAdmin = $this->message->user->is_admin;
        $appName = config('app.name');

        if ($isFromAdmin) {
            // Notifying the ticket owner about an admin reply: no message content, just a
            // notice to sign in — keeps the user's inbox free of impersonation-style content.
            return (new MailMessage)
                ->subject("{$appName}: New reply on \"{$ticket->subject}\"")
                ->greeting('Hello,')
                ->line('Our support team replied to your ticket "'.$ticket->subject.'".')
                ->line('Sign in to view the message and reply.')
                ->action('View ticket', route('support.tickets.show', $ticket));
        }

        // Notifying an admin about a user's message: include the full message and any
        // attached image, so admins can triage from their inbox without signing in first.
        $mail = (new MailMessage)
            ->subject("{$appName}: New support message — \"{$ticket->subject}\"")
            ->greeting('Hello,')
            ->line('"'.$ticket->user->email.'" wrote on ticket "'.$ticket->subject.'":')
            ->line($this->message->body);

        if ($this->message->image_path) {
            $mail->line('![Attachment]('.$this->message->imageUrl().')');
        }

        return $mail->action('View ticket', route('admin.tickets.show', $ticket));
    }
}
