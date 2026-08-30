<?php

declare(strict_types=1);

namespace App\Notifications\Authentication;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordRecoveryCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password recovery code')
            ->greeting('Hello,')
            ->line('Use the following code to reset your password: '.$this->code)
            ->action('Enter recovery code', route('password.otp', ['email' => $notifiable->email]))
            ->line('This code expires in '.config('authentication.otp.expires_after_minutes').' minutes.')
            ->line('If you did not request this, no action is required.');
    }
}
