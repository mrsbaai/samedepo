<?php

declare(strict_types=1);

namespace App\Fraud\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Device extends Model
{
    protected $fillable = ['fingerprint', 'user_agent', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
