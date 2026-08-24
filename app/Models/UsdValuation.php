<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsdValuation extends Model
{
    use HasFactory;

    protected $fillable = [
        'network',
        'conversion_value',
    ];

    protected function casts(): array
    {
        return [
            'conversion_value' => 'decimal:6',
        ];
    }
}
