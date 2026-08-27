<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GasTopup extends Model
{
    use HasFactory;

    protected $fillable = [
        'treasury_wallet_id',
        'network',
        'recipient_address',
        'recipient_index',
        'amount',
        'tx_hash',
        'status',
        'error_message',
        'broadcasted_at',
        'confirmed_at',
        'is_open',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'recipient_index' => 'integer',
            'broadcasted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function treasuryWallet(): BelongsTo
    {
        return $this->belongsTo(TreasuryWallet::class);
    }

    public function gasExpenses(): HasMany
    {
        return $this->hasMany(GasExpense::class);
    }
}
