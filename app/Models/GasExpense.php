<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GasExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'gas_topup_id',
        'network',
        'tx_hash',
        'amount',
        'expensable_type',
        'expensable_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
        ];
    }

    public function gasTopup(): BelongsTo
    {
        return $this->belongsTo(GasTopup::class);
    }

    public function expensable(): MorphTo
    {
        return $this->morphTo();
    }
}
