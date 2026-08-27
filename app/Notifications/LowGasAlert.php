<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowGasAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $network,
        private readonly string $balance,
        private readonly string $threshold,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Low gas reserve: {$this->network}")
            ->greeting('Hello,')
            ->line("The {$this->network} treasury gas balance is {$this->balance}, below the configured reserve threshold of {$this->threshold}.")
            ->line('Gas-funded transfers may remain pending until the treasury is replenished.')
            ->action('Review Treasury', route('admin.treasury'));
    }
}
