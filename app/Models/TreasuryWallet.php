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
        'address',
        'available_funds',
    ];

    protected function casts(): array
    {
        return [
            'available_funds' => 'decimal:8',
        ];
    }
}
