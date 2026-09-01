<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreasuryPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'network',
        'destination_address',
        'amount',
        'network_fee',
        'tx_hash',
        'status',
        'error_message',
        'created_by',
        'sent_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'network_fee' => 'decimal:8',
            'sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
