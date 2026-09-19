<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkSetting extends Model
{
    protected $fillable = [
        'network',
        'min_deposit',
        'withdrawal_min_usd',
        'sweep_min_usd',
        'profit_address',
    ];

    protected function casts(): array
    {
        return [
            'min_deposit' => 'decimal:8',
            'withdrawal_min_usd' => 'decimal:2',
            'sweep_min_usd' => 'decimal:2',
        ];
    }
}
