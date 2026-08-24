<?php

declare(strict_types=1);

namespace App\Fraud\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityLink extends Model
{
    protected $fillable = ['user_id', 'linked_user_id', 'strength', 'reasons'];

    protected function casts(): array
    {
        return [
            'strength' => 'integer',
            'reasons' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }
}
