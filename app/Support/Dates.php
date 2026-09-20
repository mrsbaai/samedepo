<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

final class Dates
{
    public static function humanFlat(?Carbon $at): string
    {
        if ($at === null) {
            return '';
        }

        return $at->diffForHumans().' ('.$at->copy()->utc()->format('Y-m-d H:i').')';
    }
}
