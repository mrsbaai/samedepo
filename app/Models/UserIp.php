<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIp extends Model
{
    protected $fillable = ['user_id', 'ip_address', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
