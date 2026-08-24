<?php

declare(strict_types=1);

namespace App\Fraud\Models;

use Illuminate\Database\Eloquent\Model;

class FraudLevelPolicy extends Model
{
    public const LEVELS = ['low', 'medium', 'high', 'critical'];

    public const USER_STATUSES = ['active', 'review', 'blocked'];

    private const DEFAULTS = [
        'low' => ['user_status' => 'active', 'block_fingerprint' => false, 'block_ip' => false, 'notify_admin' => false],
        'medium' => ['user_status' => 'review', 'block_fingerprint' => false, 'block_ip' => false, 'notify_admin' => false],
        'high' => ['user_status' => 'review', 'block_fingerprint' => true, 'block_ip' => false, 'notify_admin' => true],
        'critical' => ['user_status' => 'blocked', 'block_fingerprint' => true, 'block_ip' => true, 'notify_admin' => true],
    ];

    protected $fillable = ['level', 'user_status', 'block_fingerprint', 'block_ip', 'notify_admin'];

    protected function casts(): array
    {
        return [
            'block_fingerprint' => 'boolean',
            'block_ip' => 'boolean',
            'notify_admin' => 'boolean',
        ];
    }

    public static function forLevel(string $level): self
    {
        return self::query()->firstOrCreate(
            ['level' => $level],
            self::DEFAULTS[$level] ?? self::DEFAULTS['low']
        );
    }
}
