<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EnergyRental extends Model
{
    protected $fillable = [
        'network',
        'receiver_address',
        'receiver_index',
        'purpose',
        'purposable_type',
        'purposable_id',
        'energy',
        'duration_sec',
        'order_id',
        'unit_price_sun',
        'cost_native',
        'status',
        'error_message',
        'ordered_at',
        'filled_at',
        'expires_at',
        'fee_recovered_at',
    ];

    protected function casts(): array
    {
        return [
            'receiver_index' => 'integer',
            'energy' => 'integer',
            'duration_sec' => 'integer',
            'unit_price_sun' => 'integer',
            'cost_native' => 'decimal:8',
            'ordered_at' => 'datetime',
            'filled_at' => 'datetime',
            'expires_at' => 'datetime',
            'fee_recovered_at' => 'datetime',
        ];
    }

    public function purposable(): MorphTo
    {
        return $this->morphTo();
    }
}
