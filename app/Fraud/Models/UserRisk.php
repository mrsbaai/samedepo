<?php

declare(strict_types=1);

namespace App\Fraud\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRisk extends Model
{
    protected $fillable = ['user_id', 'score', 'level', 'breakdown', 'calculated_at'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'breakdown' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function levelFor(int $score): string
    {
        foreach (config('security.fraud.levels') as $level => $range) {
            if ($score >= $range['min'] && $score <= $range['max']) {
                return $level;
            }
        }

        return 'critical';
    }
}
