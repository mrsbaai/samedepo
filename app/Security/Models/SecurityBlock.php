<?php

declare(strict_types=1);

namespace App\Security\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SecurityBlock extends Model
{
    public const TYPE_IP = 'ip';

    public const TYPE_DEVICE = 'device';

    protected $fillable = ['type', 'value', 'reason', 'source', 'created_by'];

    protected static function booted(): void
    {
        $flush = function (SecurityBlock $block): void {
            Cache::forget("security.blocked.{$block->type}");
        };

        static::saved($flush);
        static::deleted($flush);
    }

    /** @return array<int, string> */
    public static function blockedValues(string $type): array
    {
        return Cache::remember(
            "security.blocked.{$type}",
            300,
            fn (): array => self::query()->where('type', $type)->pluck('value')->all()
        );
    }
}
