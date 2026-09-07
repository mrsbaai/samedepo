<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockchainScanState extends Model
{
    protected $fillable = [
        'network',
        'last_scanned_block',
    ];

    protected function casts(): array
    {
        return [
            'last_scanned_block' => 'integer',
        ];
    }
}
