<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deposit extends Model
{
    use BelongsToOwner;
    use HasFactory;

    protected $fillable = [
        'deposit_address_id',
        'customer_id',
        'user_id',
        'network',
        'tx_hash',
        'gross_amount',
        'fee_amount',
        'credited_amount',
        'confirmation_count',
        'status',
        'detected_at',
        'credited_at',
        'swept_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:8',
            'fee_amount' => 'decimal:8',
            'credited_amount' => 'decimal:8',
            'detected_at' => 'datetime',
            'credited_at' => 'datetime',
            'swept_at' => 'datetime',
        ];
    }

    public function depositAddress(): BelongsTo
    {
        return $this->belongsTo(DepositAddress::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
