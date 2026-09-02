<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\TreasurySweepService;

class FakeBlockchainBroadcaster implements BlockchainBroadcaster
{
    public ?string $hash = 'sweep-tx-123';

    public ?string $topupHash = 'topup-tx-123';

    public ?string $fee = '0.00100000';

    public ?string $balance = '10.00000000';

    public ?string $recipientBalance = null;

    public ?string $treasuryBalance = null;

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

    public function getNativeBalance(string $network, int $index): ?string
    {
        if ($index === 0) {
            return $this->treasuryBalance ?? $this->balance;
        }

        return $this->recipientBalance ?? $this->balance;
    }

    public function getTronResource(int $index): ?array
    {
        return [
            'energy_limit' => 100000,
            'energy_used' => 0,
            'bandwidth_limit' => 100000,
            'bandwidth_used' => 0,
        ];
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        return [
            'status' => $txHash === $this->topupHash ? 'pending' : 'confirmed',
            'fee' => '0.00010000',
            'confirmations' => 3,
        ];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return $this->fee;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return $this->topupHash;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
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

beforeEach(function () {
    PlatformSettings::instance()->update([
        'sweep_min_usd_bitcoin' => '0.00',
        'sweep_min_usd_usdt_trc20' => '0.00',
        'sweep_min_usd_usdt_erc20' => '0.00',
    ]);

    foreach (['bitcoin', 'usdt_trc20', 'usdt_erc20'] as $network) {
        UsdValuation::factory()->create(['network' => $network, 'conversion_value' => '1.000000']);
    }
});

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
    expect($wallet->available_funds)->toBe('1.99990000');

    $sweep = TreasurySweep::query()->where('deposit_address_id', $address->id)->first();
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
    expect(TreasurySweep::query()->where('deposit_address_id', $address->id)->count())->toBe(1);
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

    expect(TreasurySweep::query()->where('deposit_address_id', $address->id)->count())->toBe(0);
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
    expect($wallet->available_funds)->toBe('6.99980000');
});

test('it records a gas expense when a token sweep is confirmed', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20', 'derivation_index' => 5]);
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0, 'available_funds' => 0]);

    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_erc20',
        'gross_amount' => '10.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    $sweeper = createSweeper();
    $sweeper->sweep();

    $sweep = TreasurySweep::query()->where('deposit_address_id', $address->id)->first();
    expect($sweep)->not->toBeNull();
    expect($sweep->status)->toBe('confirmed');
    expect($sweep->tx_hash)->toBe('sweep-tx-123');

    $expense = GasExpense::query()->where('expensable_type', TreasurySweep::class)->where('expensable_id', $sweep->id)->first();
    expect($expense)->not->toBeNull();
    expect($expense->amount)->toBe('0.00010000');
});

test('it leaves a token sweep pending when gas is low and creates one top-up', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20', 'derivation_index' => 5, 'address' => '0x123']);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'usdt_erc20',
        'reserve_threshold' => '0.02000000',
        'top_up_amount' => '0.03000000',
        'max_top_up' => '0.05000000',
    ]);

    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_erc20',
        'gross_amount' => '10.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    $broadcaster = new FakeBlockchainBroadcaster;
    $broadcaster->recipientBalance = '0.00010000';
    $broadcaster->treasuryBalance = '1000.00000000';
    $broadcaster->hash = null;

    $sweeper = new TreasurySweepService($broadcaster);
    $sweeper->sweep();

    $deposit->refresh();
    expect($deposit->swept_at)->toBeNull();

    $topups = GasTopup::query()->where('network', 'usdt_erc20')->where('recipient_address', '0x123')->get();
    expect($topups)->toHaveCount(1);
    expect($topups->first()->status)->toBe('broadcast');

    $sweeper->sweep();
    expect(GasTopup::query()->where('network', 'usdt_erc20')->where('recipient_address', '0x123')->count())->toBe(1);
});
