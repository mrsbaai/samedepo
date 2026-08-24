<?php

declare(strict_types=1);

namespace App\Security\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class DetectorSetting extends Model
{
    protected $fillable = ['key', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('security.disabled_detectors'));
    }

    /** Detectors are enabled unless a row explicitly disables them. */
    public static function isEnabled(string $key): bool
    {
        return ! in_array($key, self::disabledKeys(), true);
    }

    /** @return array<int, string> */
    public static function disabledKeys(): array
    {
        return Cache::remember(
            'security.disabled_detectors',
            300,
            fn (): array => self::query()->where('enabled', false)->pluck('key')->all()
        );
    }
}
