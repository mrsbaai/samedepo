<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreasurySweep extends Model
{
    use HasFactory;

    protected $fillable = [
        'deposit_id',
        'deposit_address_id',
        'deposit_ids',
        'network',
        'amount',
        'tx_hash',
        'status',
        'error_message',
        'confirmed_at',
        'fee_recovered_at',
        'recovered_withdrawal_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'deposit_ids' => 'array',
            'confirmed_at' => 'datetime',
            'fee_recovered_at' => 'datetime',
        ];
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function depositAddress(): BelongsTo
    {
        return $this->belongsTo(DepositAddress::class);
    }

    public function recoveredByWithdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class, 'recovered_withdrawal_id');
    }
}
