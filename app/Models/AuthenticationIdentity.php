<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthenticationIdentity extends Model
{
    protected $fillable = [
        'provider',
        'provider_user_hash',
        'provider_user_id',
        'access_token',
        'token_expires_at',
    ];

    protected $hidden = [
        'provider_user_id',
        'provider_user_hash',
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'provider_user_id' => 'encrypted',
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
