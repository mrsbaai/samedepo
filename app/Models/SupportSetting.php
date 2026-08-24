<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportSetting extends Model
{
    protected $fillable = [
        'special_instructions',
        'service_description',
        'service_use_case',
    ];

    public static function current(): self
    {
        return self::query()->firstOrCreate([], []);
    }
}
