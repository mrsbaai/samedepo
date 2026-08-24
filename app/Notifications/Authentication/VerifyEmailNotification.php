<?php

declare(strict_types=1);

namespace App\Notifications\Authentication;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email address')
            ->greeting('Hello,')
            ->line('Enter the following code to verify your email address:')
            ->line("**{$this->code}**")
            ->line('Or use the button below.')
            ->action('Verify email address', $this->verificationUrl($notifiable))
            ->line('This code expires in '.config('authentication.email_verification.expires_after_minutes').' minutes.')
            ->line('If you did not create an account, no further action is required.');
    }

    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('authentication.email_verification.expires_after_minutes')),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }
}
