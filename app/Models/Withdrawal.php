<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Withdrawal extends Model
{
    use BelongsToOwner;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'network',
        'gross_amount',
        'network_fee',
        'network_fee_native',
        'amount_sent',
        'destination_address',
        'mode',
        'status',
        'tx_hash',
        'decided_at',
        'decided_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:8',
            'network_fee' => 'decimal:8',
            'network_fee_native' => 'decimal:8',
            'amount_sent' => 'decimal:8',
            'decided_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
