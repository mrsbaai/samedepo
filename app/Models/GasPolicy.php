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
        'energy_mode',
        'rent_max_price_sun',
        'rent_duration_sec',
        'rent_energy_headroom_percent',
        'rent_float_alert_trx',
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
            'rent_max_price_sun' => 'integer',
            'rent_duration_sec' => 'integer',
            'rent_energy_headroom_percent' => 'integer',
            'rent_float_alert_trx' => 'decimal:8',
            'alert_cooldown' => 'integer',
            'last_alert_at' => 'datetime',
        ];
    }
}
