<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WebhookEndpointFailing extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your samedepo webhook endpoint is failing')
            ->greeting('Hello,')
            ->line("samedepo could not deliver a webhook to {$this->url}.")
            ->line('Please check that your endpoint is reachable and returns an HTTP 2xx status code on every request.')
            ->action('Review Webhook Settings', route('webhook-settings'));
    }
}
