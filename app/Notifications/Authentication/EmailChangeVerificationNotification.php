<?php

declare(strict_types=1);

namespace App\Notifications\Authentication;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangeVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $verificationUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your new email address')
            ->greeting('Hello,')
            ->line('Please confirm this email address for your account by clicking the button below.')
            ->action('Verify email address', $this->verificationUrl)
            ->line('This link will expire in '.config('authentication.email_change.expires_after_minutes').' minutes.')
            ->line('If you did not request this change, you can safely ignore this email.');
    }
}
