<?php

declare(strict_types=1);

namespace App\Services\Blockchain\PriceFeed;

interface PriceFeedProvider
{
    /** @return array<string, float|int> */
    public function prices(): array;
}
