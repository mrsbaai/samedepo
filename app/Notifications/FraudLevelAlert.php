<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Fraud\Models\FraudAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FraudLevelAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly FraudAlert $alert,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $level = strtoupper($this->alert->level);

        return (new MailMessage)
            ->subject("Fraud alert: user #{$this->alert->user_id} reached {$level}")
            ->greeting('Hello,')
            ->line("User #{$this->alert->user_id} reached fraud level {$level} with a score of {$this->alert->score}/100.")
            ->line('The configured fraud-level policy has been applied automatically.')
            ->action('Review in Fraud Intelligence', route('admin.security.fraud'));
    }
}
