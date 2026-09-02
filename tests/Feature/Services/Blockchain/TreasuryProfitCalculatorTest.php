<?php

declare(strict_types=1);

use App\Models\Balance;
use App\Models\Deposit;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\TreasuryProfitCalculator;

function seedProfitExample(string $ownerBalances = '70.00000000'): void
{
    $owner = User::factory()->create();

    TreasuryWallet::factory()->create([
        'network' => 'usdt_trc20',
        'available_funds' => '100.00000000',
    ]);
    Deposit::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '20.00000000',
        'status' => 'credited',
        'swept_at' => null,
    ]);
    Balance::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'amount' => $ownerBalances,
    ]);
    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '20.00000000',
        'status' => 'approved',
    ]);
    TreasuryPayout::create([
        'network' => 'usdt_trc20',
        'destination_address' => 'T111111111111111111111111111111111',
        'amount' => '5.00000000',
        'status' => 'sent',
        'created_by' => $owner->id,
    ]);
    UsdValuation::create(['network' => 'usdt_trc20', 'conversion_value' => '1.000000']);
}

test('it calculates withdrawable treasury profit from existing balances', function () {
    seedProfitExample();

    $profit = (new TreasuryProfitCalculator)->forNetwork('usdt_trc20');

    expect($profit['equity'])->toBe('30.00000000')
        ->and($profit['spendable'])->toBe('80.00000000')
        ->and($profit['withdrawable'])->toBe('30.00000000')
        ->and($profit['paid_out'])->toBe('5.00000000')
        ->and($profit['withdrawable_usd'])->toBe('30.00000000');
});

test('it reports a deficit without allowing negative withdrawals', function () {
    seedProfitExample('130.00000000');

    $calculator = new TreasuryProfitCalculator;
    $profit = $calculator->forNetwork('usdt_trc20');

    expect($profit['equity'])->toBe('-30.00000000')
        ->and($profit['withdrawable'])->toBe('0.00000000')
        ->and($calculator->summary()['has_deficit'])->toBeTrue();
});

test('it returns zero values for a network without a treasury wallet', function () {
    $profit = (new TreasuryProfitCalculator)->forNetwork('bitcoin');

    expect(array_unique(array_values($profit)))->toBe(['0.00000000']);
});

test('it includes balances belonging to every owner', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => '10.00000000']);
    Balance::factory()->create(['user_id' => $first->id, 'network' => 'bitcoin', 'amount' => '2.00000000']);
    Balance::factory()->create(['user_id' => $second->id, 'network' => 'bitcoin', 'amount' => '3.00000000']);

    expect((new TreasuryProfitCalculator)->forNetwork('bitcoin')['owner_balances'])->toBe('5.00000000');
});
