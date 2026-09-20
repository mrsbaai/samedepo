<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

final class Dates
{
    public static function humanFlat(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        return $at->diffForHumans().' ('.$at->copy()->utc()->format('Y-m-d H:i').')';
    }
}
