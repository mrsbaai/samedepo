<?php

declare(strict_types=1);

namespace App\Fraud\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudAlert extends Model
{
    protected $fillable = ['user_id', 'level', 'score', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
