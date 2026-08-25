<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\TreasurySweepService;

class FakeBlockchainBroadcaster implements BlockchainBroadcaster
{
    public ?string $hash = 'sweep-tx-123';

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return $this->hash;
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        return null;
    }

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        return null;
    }
}

function createSweeper(?string $hash = 'sweep-tx-123'): TreasurySweepService
{
    $broadcaster = new FakeBlockchainBroadcaster;
    $broadcaster->hash = $hash;

    return new TreasurySweepService($broadcaster);
}

test('it sweeps a credited deposit into the treasury wallet', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    $wallet = TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 0]);

    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '2.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    expect(Deposit::query()->where('status', 'credited')->whereNull('swept_at')->count())->toBe(1);

    $sweeper = createSweeper();
    $sweeper->sweep();

    $deposit->refresh();
    expect($deposit->swept_at)->not->toBeNull();

    $wallet->refresh();
    expect($wallet->available_funds)->toBe('2.00000000');

    $sweep = TreasurySweep::query()->where('deposit_id', $deposit->id)->first();
    expect($sweep)->not->toBeNull();
    expect($sweep->status)->toBe('confirmed');
    expect($sweep->tx_hash)->toBe('sweep-tx-123');
    expect($sweep->amount)->toBe('2.00000000');
});

test('it does not mark a deposit swept when the broadcaster returns null', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 0]);

    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    createSweeper(null)->sweep();

    $deposit->refresh();
    expect($deposit->swept_at)->toBeNull();
    expect(TreasurySweep::query()->where('deposit_id', $deposit->id)->count())->toBe(1);
});

test('it skips deposits that have already been swept', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 0]);

    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.00000000',
        'status' => 'credited',
        'credited_at' => now(),
        'swept_at' => now(),
    ]);

    createSweeper()->sweep();

    expect(TreasurySweep::query()->where('deposit_id', $deposit->id)->count())->toBe(0);
});

test('it skips deposits without a matching treasury wallet', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);

    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    createSweeper()->sweep();

    $deposit->refresh();
    expect($deposit->swept_at)->toBeNull();
    expect(TreasurySweep::query()->count())->toBe(0);
});

test('it accumulates multiple sweeps into the treasury wallet balance', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $wallet = TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 5]);

    foreach (range(1, 2) as $i) {
        $customer = Customer::factory()->create(['user_id' => $owner->id]);
        $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
        Deposit::factory()->create([
            'deposit_address_id' => $address->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'network' => 'bitcoin',
            'gross_amount' => '1.00000000',
            'status' => 'credited',
            'credited_at' => now(),
        ]);
    }

    createSweeper()->sweep();

    $wallet->refresh();
    expect($wallet->available_funds)->toBe('7.00000000');
});
