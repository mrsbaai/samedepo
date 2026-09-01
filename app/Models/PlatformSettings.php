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
        'api_requests_per_minute',
        'min_deposit_bitcoin',
        'min_deposit_usdt_trc20',
        'min_deposit_usdt_erc20',
        'withdrawal_min_usd_bitcoin',
        'withdrawal_min_usd_usdt_trc20',
        'withdrawal_min_usd_usdt_erc20',
        'sweep_min_usd_bitcoin',
        'sweep_min_usd_usdt_trc20',
        'sweep_min_usd_usdt_erc20',
        'sweep_max_age_days',
        'withdrawal_fee_buffer_percent',
        'confirmations_bitcoin',
        'confirmations_usdt_trc20',
        'confirmations_usdt_erc20',
    ];

    protected function casts(): array
    {
        return [
            'global_deposit_fee_percent' => 'decimal:2',
            'api_requests_per_minute' => 'integer',
            'min_deposit_bitcoin' => 'decimal:8',
            'min_deposit_usdt_trc20' => 'decimal:8',
            'min_deposit_usdt_erc20' => 'decimal:8',
            'withdrawal_min_usd_bitcoin' => 'decimal:2',
            'withdrawal_min_usd_usdt_trc20' => 'decimal:2',
            'withdrawal_min_usd_usdt_erc20' => 'decimal:2',
            'sweep_min_usd_bitcoin' => 'decimal:2',
            'sweep_min_usd_usdt_trc20' => 'decimal:2',
            'sweep_min_usd_usdt_erc20' => 'decimal:2',
            'sweep_max_age_days' => 'integer',
            'withdrawal_fee_buffer_percent' => 'decimal:2',
        ];
    }

    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'global_deposit_fee_percent' => 2.00,
            'default_withdrawal_mode' => 'approval',
            'api_requests_per_minute' => 60,
            'min_deposit_bitcoin' => 0.00010000,
            'min_deposit_usdt_trc20' => 10.00000000,
            'min_deposit_usdt_erc20' => 10.00000000,
            'withdrawal_min_usd_bitcoin' => 100.00,
            'withdrawal_min_usd_usdt_trc20' => 100.00,
            'withdrawal_min_usd_usdt_erc20' => 100.00,
            'sweep_min_usd_bitcoin' => 200.00,
            'sweep_min_usd_usdt_trc20' => 25.00,
            'sweep_min_usd_usdt_erc20' => 300.00,
            'sweep_max_age_days' => 30,
            'withdrawal_fee_buffer_percent' => 20.00,
            'confirmations_bitcoin' => 3,
            'confirmations_usdt_trc20' => 12,
            'confirmations_usdt_erc20' => 12,
        ]);
    }
}
