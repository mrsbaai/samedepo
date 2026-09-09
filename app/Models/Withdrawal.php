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
    public const ERROR_LABELS = [
        'treasury_insufficient_funds' => 'Waiting for treasury funds',
        'fee_unavailable' => 'Waiting for a network fee estimate',
        'fee_conversion_failed' => 'Waiting for a USD valuation',
        'gas_unavailable' => 'Waiting for treasury gas',
        'insufficient_gas' => 'Waiting for treasury gas',
        'broadcast_failed' => 'Last send attempt failed; retrying',
    ];

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
        'last_error',
        'attempts',
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
            'attempts' => 'integer',
            'reconcile_attempts' => 'integer',
        ];
    }

    public function lastErrorLabel(): ?string
    {
        if ($this->last_error === null) {
            return null;
        }

        $code = explode(':', $this->last_error, 2)[0];

        return self::ERROR_LABELS[$code] ?? 'Retrying automatically';
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
