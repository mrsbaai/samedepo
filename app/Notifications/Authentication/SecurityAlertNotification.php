<?php

declare(strict_types=1);

namespace App\Notifications\Authentication;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class SecurityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $eventType,
    ) {}

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello,')
            ->line($this->message())
            ->line('If you did not make this change, secure your account immediately.');

        if ($this->eventType === 'account_deleted' && $notifiable instanceof \App\Models\User) {
            $message->action(
                'Cancel account deletion',
                URL::signedRoute(
                    'security.delete.recover',
                    ['id' => $notifiable->id, 'email' => $notifiable->email],
                    now()->addDays((int) config('authentication.deletion.grace_period_days'))
                )
            );
        }

        return $message;
    }

    private function subject(): string
    {
        return match ($this->eventType) {
            'account_deleted' => 'Account deletion requested',
            'account_deletion_completed' => 'Account permanently deleted',
            'account_deletion_recovered' => 'Account deletion cancelled',
            default => 'Account security update',
        };
    }

    private function message(): string
    {
        return match ($this->eventType) {
            'password_changed' => 'Your password was changed.',
            'email_change_requested' => 'An email address change was requested for your account.',
            'email_change_cancelled' => 'A pending email address change was cancelled.',
            'email_changed' => 'Your email address was changed.',
            'two_factor_enabled' => 'Two-factor authentication was enabled.',
            'two_factor_disabled' => 'Two-factor authentication was disabled.',
            'recovery_codes_regenerated' => 'Your two-factor recovery codes were regenerated.',
            'session_revoked' => 'A session was revoked from your account.',
            'account_deleted' => 'Your account deletion request was received. You can still cancel this request using the button below.',
            'account_deletion_completed' => 'Your account and its data have been permanently deleted.',
            'account_deletion_recovered' => 'Your account deletion request has been cancelled.',
            default => 'A new security-related event occurred on your account.',
        };
    }
}
