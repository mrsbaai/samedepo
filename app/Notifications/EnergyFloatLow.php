<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnergyFloatLow extends Notification implements ShouldQueue
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
            ->subject("Low energy rental float: {$this->network}")
            ->greeting('Hello,')
            ->line("The TronSave internal-account balance is {$this->balance} TRX, below the configured float alert threshold of {$this->threshold} TRX.")
            ->line('Energy rentals will fall back to burning TRX until the float is refilled — send TRX to the TronSave deposit address shown on the treasury page.')
            ->action('Review Treasury', route('admin.treasury'));
    }
}
