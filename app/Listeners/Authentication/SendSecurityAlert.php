<?php

declare(strict_types=1);

namespace App\Listeners\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSecurityAlert implements ShouldQueue
{
    public function handle(AuthenticationEvent $event): void
    {
        if ($event->user === null || ! $this->shouldNotify($event->type)) {
            return;
        }

        $event->user->notify(new SecurityAlertNotification($event->type));
    }

    private function shouldNotify(string $eventType): bool
    {
        return match ($eventType) {
            AuthenticationEvent::USER_SIGNED_IN => config('authentication.security_notifications.new_device_signin'),
            AuthenticationEvent::PASSWORD_CHANGED => config('authentication.security_notifications.password_changed'),
            AuthenticationEvent::EMAIL_CHANGE_REQUESTED,
            AuthenticationEvent::EMAIL_CHANGE_CANCELLED,
            AuthenticationEvent::EMAIL_CHANGED => config('authentication.security_notifications.email_changed'),
            AuthenticationEvent::TWO_FACTOR_ENABLED,
            AuthenticationEvent::TWO_FACTOR_DISABLED,
            AuthenticationEvent::RECOVERY_CODES_REGENERATED => config('authentication.security_notifications.two_factor_changed'),
            AuthenticationEvent::SESSION_REVOKED => config('authentication.security_notifications.session_revoked'),
            AuthenticationEvent::ACCOUNT_DELETED => config('authentication.security_notifications.account_deleted'),
            AuthenticationEvent::ACCOUNT_DELETION_RECOVERED => config('authentication.security_notifications.account_deleted'),
            default => false,
        };
    }
}
