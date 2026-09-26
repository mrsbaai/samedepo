<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\EnergyRental;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\ConsolidationBiller;
use App\Services\Blockchain\FeeConverter;
use App\Services\Blockchain\TreasurySweepService;

class FakeBlockchainBroadcaster implements BlockchainBroadcaster
{
    public ?string $hash = 'sweep-tx-123';

    public ?string $topupHash = 'topup-tx-123';

    public ?string $fee = '0.00100000';

    public ?string $balance = '10.00000000';

    public ?string $recipientBalance = null;

    public ?string $treasuryBalance = null;

    public ?string $tokenBalance = '1000000.00000000';

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

    public function getTokenBalance(string $network, int $index): ?string
    {
        return $this->tokenBalance;
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

    public function estimateTransferResources(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?array
    {
        return $this->fee === null ? null : ['fee' => $this->fee, 'energy' => null];
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
    PlatformSettings::instance();
    PlatformSettings::networkSetting('bitcoin')->update(['sweep_min_usd' => '0.00']);
    PlatformSettings::networkSetting('usdt_trc20')->update(['sweep_min_usd' => '0.00']);
    PlatformSettings::networkSetting('usdt_erc20')->update(['sweep_min_usd' => '0.00']);

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
        'network' => 'native_eth',
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

test('it sweeps forfeited deposits platform-paid and never bills the owner', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
        'derivation_index' => 5,
        'address' => 'TDepositForfeit',
    ]);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'available_funds' => 0]);
    UsdValuation::factory()->create(['network' => 'native_trx', 'conversion_value' => '0.100000']);
    PlatformSettings::networkSetting('usdt_trc20')->update(['sweep_min_usd' => '1000000.00']);

    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'status' => 'forfeited',
        'forfeited_at' => now(),
    ]);

    $broadcaster = new FakeBlockchainBroadcaster;
    $broadcaster->recipientBalance = '0.00010000';
    $broadcaster->treasuryBalance = '1000.00000000';
    $sweeper = new TreasurySweepService($broadcaster);
    $sweeper->sweep();

    // Below the owner threshold, yet a platform-paid sweep is created anyway.
    $sweep = TreasurySweep::query()->where('deposit_address_id', $address->id)->sole();
    expect($sweep->platform_paid)->toBeTrue()
        ->and($sweep->deposit_ids)->toBe([$deposit->id])
        ->and($sweep->amount)->toBe('5.00000000')
        ->and($sweep->status)->toBe('pending');

    // The gas top-up the platform sent for this sweep is linked to it.
    $topup = GasTopup::query()->where('recipient_address', 'TDepositForfeit')->sole();
    expect($topup->treasury_sweep_id)->toBe($sweep->id);
    $topup->update(['status' => 'confirmed', 'confirmed_at' => now(), 'is_open' => 'done']);

    $rental = EnergyRental::create([
        'network' => 'usdt_trc20',
        'receiver_address' => 'TDepositForfeit',
        'receiver_index' => 5,
        'purpose' => 'sweep',
        'purposable_type' => $sweep->getMorphClass(),
        'purposable_id' => $sweep->id,
        'energy' => 65000,
        'duration_sec' => 3600,
        'order_id' => 'order-1',
        'status' => 'filled',
        'cost_native' => '0.50000000',
        'ordered_at' => now()->subHour(),
        'filled_at' => now()->subHour(),
        'expires_at' => now()->addHour(),
    ]);

    $broadcaster->recipientBalance = '10.00000000';
    $sweeper->sweep();

    $deposit->refresh();
    $sweep->refresh();
    expect($deposit->swept_at)->not->toBeNull()
        ->and($sweep->status)->toBe('confirmed')
        ->and($sweep->fee_recovered_at)->not->toBeNull()
        ->and($topup->fresh()->fee_recovered_at)->not->toBeNull()
        ->and($rental->fresh()->fee_recovered_at)->not->toBeNull();

    $sweeper->billConsolidationCosts();

    expect(LedgerEntry::query()->where('reason', 'consolidation_fee')->count())->toBe(0)
        ->and((new ConsolidationBiller(new FeeConverter))->outstanding($owner->id, 'usdt_trc20'))->toBe('0.00000000');
});

test('it sweeps credited deposits alongside forfeited ones on the same address', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
        'derivation_index' => 5,
    ]);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'available_funds' => 0]);

    $forfeited = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'status' => 'forfeited',
        'forfeited_at' => now(),
    ]);
    $credited = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '10.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    createSweeper()->sweep();

    $sweep = TreasurySweep::query()->where('deposit_address_id', $address->id)->sole();
    expect($sweep->platform_paid)->toBeTrue()
        ->and($sweep->deposit_ids)->toEqualCanonicalizing([$forfeited->id, $credited->id])
        ->and($sweep->amount)->toBe('15.00000000')
        ->and($sweep->status)->toBe('confirmed');

    expect($forfeited->fresh()->swept_at)->not->toBeNull()
        ->and($credited->fresh()->swept_at)->not->toBeNull()
        ->and(LedgerEntry::query()->where('reason', 'consolidation_fee')->count())->toBe(0);
});

test('a platform-paid sweep is polled in flight and never piggybacks siblings', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_erc20',
        'derivation_index' => 5,
        'address' => '0xShared',
    ]);
    $sibling = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdc_erc20',
        'derivation_index' => 5,
        'address' => '0xShared',
    ]);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'available_funds' => 0]);
    TreasuryWallet::factory()->create(['network' => 'usdc_erc20', 'available_funds' => 0]);
    PlatformSettings::networkSetting('usdc_erc20')->update(['sweep_min_usd' => '0.00']);

    $forfeited = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_erc20',
        'gross_amount' => '5.00000000',
        'status' => 'forfeited',
        'forfeited_at' => now(),
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $sibling->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdc_erc20',
        'gross_amount' => '10.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    $broadcaster = new FakeBlockchainBroadcaster;
    $broadcaster->hash = null;
    $sweeper = new TreasurySweepService($broadcaster);
    $sweeper->sweep();

    $platform = TreasurySweep::query()->where('deposit_address_id', $address->id)->sole();
    expect($platform->platform_paid)->toBeTrue();

    // A normal sweep would have picked up the usdc sibling — this one must not.
    expect(TreasurySweep::query()->whereNotNull('piggybacked_on_sweep_id')->count())->toBe(0);
    expect(TreasurySweep::query()->where('deposit_address_id', $sibling->id)->sole()->platform_paid)->toBeFalse();

    // Next tick: the in-flight platform sweep is polled and confirms.
    $this->travel(3)->minutes();
    $broadcaster->hash = 'sweep-tx-123';
    $sweeper->sweep();

    expect($platform->fresh()->status)->toBe('confirmed')
        ->and($forfeited->fresh()->swept_at)->not->toBeNull()
        ->and(TreasurySweep::query()->whereNotNull('piggybacked_on_sweep_id')->count())->toBe(0);
});

test('a forfeited native sweep at or below the fee floor is skipped', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 0]);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '0.00150000', // exactly 1.5 × the 0.001 fake fee
        'status' => 'forfeited',
        'forfeited_at' => now(),
    ]);

    createSweeper()->sweep();

    expect(TreasurySweep::query()->where('deposit_address_id', $address->id)->count())->toBe(0);
});

test('a forfeited native sweep above the fee floor is created', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => 0]);

    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '0.01000000',
        'status' => 'forfeited',
        'forfeited_at' => now(),
    ]);

    createSweeper()->sweep();

    $sweep = TreasurySweep::query()->where('deposit_address_id', $address->id)->sole();
    expect($sweep->platform_paid)->toBeTrue()
        ->and($sweep->status)->toBe('confirmed')
        ->and($deposit->fresh()->swept_at)->not->toBeNull();
});

test('an owner-funded top-up to a swept address stays billable', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
        'derivation_index' => 5,
        'address' => 'TDepositOwner',
    ]);
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'available_funds' => 0]);
    UsdValuation::factory()->create(['network' => 'native_trx', 'conversion_value' => '0.100000']);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '5.00000000',
        'status' => 'forfeited',
        'forfeited_at' => now(),
    ]);

    $broadcaster = new FakeBlockchainBroadcaster;
    $broadcaster->recipientBalance = '0.00010000';
    $broadcaster->treasuryBalance = '1000.00000000';
    (new TreasurySweepService($broadcaster))->sweep();

    $sweep = TreasurySweep::query()->where('deposit_address_id', $address->id)->sole();
    $sweep->update(['status' => 'confirmed', 'confirmed_at' => now()]);

    $platformTopup = GasTopup::query()->where('treasury_sweep_id', $sweep->id)->sole();
    $platformTopup->update(['status' => 'confirmed', 'confirmed_at' => now(), 'is_open' => (string) $platformTopup->id]);

    // The owner's own top-up to the same address is not platform-paid.
    $ownerTopup = GasTopup::create([
        'treasury_wallet_id' => $wallet->id,
        'network' => 'usdt_trc20',
        'kind' => 'topup',
        'recipient_address' => 'TDepositOwner',
        'recipient_index' => 5,
        'amount' => '2.00000000',
        'tx_hash' => 'owner-topup-1',
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'is_open' => 'done',
    ]);

    $biller = new ConsolidationBiller(new FeeConverter);

    // Only the owner's 2.0 TRX top-up is attributable: 2.0 × 0.10 USD / 1.0 USD.
    expect($biller->outstanding($owner->id, 'usdt_trc20'))->toBe('0.20000000');

    $biller->settle($owner->id, 'usdt_trc20', '0.20000000');

    expect($ownerTopup->fresh()->fee_recovered_at)->not->toBeNull()
        ->and($platformTopup->fresh()->fee_recovered_at)->toBeNull()
        ->and($sweep->fresh()->fee_recovered_at)->toBeNull();
});
