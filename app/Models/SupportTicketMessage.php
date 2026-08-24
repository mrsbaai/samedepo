<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SupportTicketMessage extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'author_name',
        'author_avatar',
        'body',
        'image_path',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function authorDisplayName(): string
    {
        if ($this->author_name) {
            return $this->author_name;
        }

        if (! $this->user->is_admin) {
            return $this->user->email;
        }

        $identity = SupportIdentity::forRole('support');

        return self::formatAgentName($identity->name, $identity->role);
    }

    public function authorAvatarUrl(): ?string
    {
        return $this->author_avatar;
    }

    public static function formatAgentName(?string $agentName, ?string $agentRole = null): string
    {
        $role = $agentRole ?: 'support';
        $label = ucfirst($role);

        return $agentName ? "{$agentName} from {$label}" : $label;
    }
}
