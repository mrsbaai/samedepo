<?php

declare(strict_types=1);

use App\Models\UsdValuation;

test('a usd valuation is unique per network', function () {
    UsdValuation::create([
        'network' => 'bitcoin',
        'conversion_value' => 65000.00,
    ]);
    UsdValuation::create([
        'network' => 'usdt_trc20',
        'conversion_value' => 1.00,
    ]);

    expect(UsdValuation::count())->toBe(2);
});
