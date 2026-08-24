<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'subject',
        'status',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportTicketMessage::class, 'support_ticket_id')->latestOfMany();
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function statusLabelForUser(): string
    {
        if ($this->status === self::STATUS_CLOSED) {
            return 'Closed';
        }

        $lastMessage = $this->latestMessage;

        if ($lastMessage && $lastMessage->user->is_admin) {
            return 'Support Replied';
        }

        return 'In Review';
    }

    public function statusColorForUser(): string
    {
        return match ($this->statusLabelForUser()) {
            'Closed' => 'zinc',
            'Support Replied' => 'lime',
            'In Review' => 'yellow',
            default => 'zinc',
        };
    }

    public function unreadCountFor(User $viewer): int
    {
        return $this->messages()->where('user_id', '!=', $viewer->id)->whereNull('read_at')->count();
    }

    public function markReadFor(User $viewer): void
    {
        $this->messages()->where('user_id', '!=', $viewer->id)->whereNull('read_at')->update(['read_at' => now()]);
    }
}
