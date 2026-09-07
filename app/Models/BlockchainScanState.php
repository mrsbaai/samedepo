<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockchainScanState extends Model
{
    protected $fillable = [
        'network',
        'last_scanned_block',
        'next_scan_at',
        'cooldown_until',
        'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'last_scanned_block' => 'integer',
            'next_scan_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }
}
