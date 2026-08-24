<?php

declare(strict_types=1);

namespace App\Listeners\Authentication;

use App\Events\Authentication\AuthenticationEvent;

class ResetAnnouncementSeen
{
    public function handle(AuthenticationEvent $event): void
    {
        if ($event->type === AuthenticationEvent::USER_SIGNED_IN) {
            session()->forget('announcement_seen');
        }
    }
}
