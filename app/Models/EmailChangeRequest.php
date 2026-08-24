<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailChangeRequest extends Model
{
    protected $fillable = [
        'pending_email',
        'verification_token',
        'expires_at',
    ];

    protected $hidden = [
        'pending_email',
        'verification_token',
    ];

    protected function casts(): array
    {
        return [
            'pending_email' => 'encrypted',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
