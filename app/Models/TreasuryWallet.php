<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TreasuryWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'network',
        'derivation_index',
        'address',
        'available_funds',
        'native_balance',
        'energy',
        'bandwidth',
        'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'available_funds' => 'decimal:8',
            'native_balance' => 'decimal:8',
            'energy' => 'integer',
            'bandwidth' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }
}
