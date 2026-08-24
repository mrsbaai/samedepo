<?php

declare(strict_types=1);

use App\Models\TreasuryWallet;

test('a treasury wallet is unique per network', function () {
    TreasuryWallet::create([
        'network' => 'bitcoin',
        'address' => 'btc-treasury-address',
        'available_funds' => 10,
    ]);

    TreasuryWallet::create([
        'network' => 'usdt_trc20',
        'address' => 'usdt-trc20-treasury-address',
        'available_funds' => 100,
    ]);

    expect(TreasuryWallet::count())->toBe(2);
});
