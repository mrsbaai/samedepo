<?php

declare(strict_types=1);

namespace App\Security\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForbiddenEvent extends Model
{
    protected $fillable = [
        'source',
        'reason',
        'path',
        'method',
        'ip_address',
        'user_id',
        'user_agent',
        'threat_event_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'threat_event_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function threatEvent(): BelongsTo
    {
        return $this->belongsTo(ThreatEvent::class);
    }
}
