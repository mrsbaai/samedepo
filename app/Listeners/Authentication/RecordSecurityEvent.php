<?php

declare(strict_types=1);

namespace App\Listeners\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\SecurityEvent;

class RecordSecurityEvent
{
    private const ALLOWED_METADATA = ['device', 'location', 'provider', 'session_id'];

    public function handle(AuthenticationEvent $event): void
    {
        $metadata = array_intersect_key($event->metadata, array_flip(self::ALLOWED_METADATA));

        SecurityEvent::query()->create([
            'user_id' => $event->user?->id,
            'event_type' => $event->type,
            'ip_address' => $event->ipAddress,
            'user_agent' => $event->userAgent,
            'device' => $metadata['device'] ?? null,
            'location' => $metadata['location'] ?? null,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
