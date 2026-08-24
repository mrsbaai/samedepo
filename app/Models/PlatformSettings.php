<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'global_deposit_fee_percent',
        'default_withdrawal_mode',
        'min_deposit_bitcoin',
        'min_deposit_usdt_trc20',
        'min_deposit_usdt_erc20',
        'withdrawal_min_usd_bitcoin',
        'withdrawal_min_usd_usdt_trc20',
        'withdrawal_min_usd_usdt_erc20',
        'confirmations_bitcoin',
        'confirmations_usdt_trc20',
        'confirmations_usdt_erc20',
    ];

    protected function casts(): array
    {
        return [
            'global_deposit_fee_percent' => 'decimal:2',
            'min_deposit_bitcoin' => 'decimal:8',
            'min_deposit_usdt_trc20' => 'decimal:8',
            'min_deposit_usdt_erc20' => 'decimal:8',
            'withdrawal_min_usd_bitcoin' => 'decimal:2',
            'withdrawal_min_usd_usdt_trc20' => 'decimal:2',
            'withdrawal_min_usd_usdt_erc20' => 'decimal:2',
        ];
    }

    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'global_deposit_fee_percent' => 1.00,
            'default_withdrawal_mode' => 'approval',
            'min_deposit_bitcoin' => 0.00010000,
            'min_deposit_usdt_trc20' => 10.00000000,
            'min_deposit_usdt_erc20' => 10.00000000,
            'withdrawal_min_usd_bitcoin' => 100.00,
            'withdrawal_min_usd_usdt_trc20' => 100.00,
            'withdrawal_min_usd_usdt_erc20' => 100.00,
            'confirmations_bitcoin' => 3,
            'confirmations_usdt_trc20' => 12,
            'confirmations_usdt_erc20' => 12,
        ]);
    }
}
