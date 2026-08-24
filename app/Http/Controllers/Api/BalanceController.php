<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\BalanceCollection;
use App\Models\Balance;

class BalanceController
{
    public function __invoke(): BalanceCollection
    {
        $balances = Balance::query()
            ->whereIn('network', array_keys(BalanceCollection::NETWORKS))
            ->get();

        return new BalanceCollection($balances);
    }
}
