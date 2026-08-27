<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GasPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'network',
        'reserve_threshold',
        'top_up_amount',
        'max_top_up',
        'manual_paused',
        'alert_cooldown',
        'last_alert_at',
    ];

    protected function casts(): array
    {
        return [
            'reserve_threshold' => 'decimal:8',
            'top_up_amount' => 'decimal:8',
            'max_top_up' => 'decimal:8',
            'manual_paused' => 'boolean',
            'alert_cooldown' => 'integer',
            'last_alert_at' => 'datetime',
        ];
    }
}
