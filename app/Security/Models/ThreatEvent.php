<?php

declare(strict_types=1);

namespace App\Security\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreatEvent extends Model
{
    protected $fillable = [
        'detector',
        'threat_type',
        'severity',
        'description',
        'payload',
        'ip_address',
        'fingerprint',
        'user_id',
        'method',
        'path',
        'blocked',
    ];

    protected function casts(): array
    {
        return [
            'severity' => 'integer',
            'blocked' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function severityLabel(): string
    {
        return match (true) {
            $this->severity >= 9 => 'critical',
            $this->severity >= 7 => 'high',
            $this->severity >= 5 => 'medium',
            default => 'low',
        };
    }

    public function confidence(): int
    {
        return min($this->severity * 10, 99);
    }
}
