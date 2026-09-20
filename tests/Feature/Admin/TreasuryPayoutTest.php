<?php

declare(strict_types=1);

use App\Models\Balance;
use App\Models\EnergyRental;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\TreasuryPayoutService;
use App\Support\Network;
use Illuminate\Support\Facades\Http;

class PayoutBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $hash = 'payout-tx-123';

    public string $receiptStatus = 'confirmed';

    public ?string $fee = '0.00100000';

    public int $broadcastCalls = 0;

    public ?array $tronResource = null;

    public ?int $transferEnergy = null;

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return null;
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
        return '1000.00000000';
    }

    public function getTokenBalance(string $network, int $index): ?string
    {
        return null;
    }

    public function getTronResource(int $index): ?array
    {
        return $this->tronResource;
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        return [
            'status' => $this->receiptStatus,
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
        return $this->fee === null ? null : ['fee' => $this->fee, 'energy' => $this->transferEnergy ?? null];
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return null;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        $this->broadcastCalls++;

        return $this->hash;
    }
}

function payoutFixture(
    string $network = 'usdt_trc20',
    string $amount = '10.00000000',
    string $available = '100.00000000',
    string $ownerBalance = '0.00000000',
    ?string $savedAddress = 'T111111111111111111111111111111111',
    ?string $destination = null,
    string $nativeBalance = '50.00000000',
    bool $valuations = true,
): array {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $address = $savedAddress;
    $destination ??= $address ?? 'T222222222222222222222222222222222';

    PlatformSettings::instance();
    PlatformSettings::networkSetting($network)->update(['profit_address' => $address]);
    $wallet = TreasuryWallet::factory()->create([
        'network' => $network,
        'derivation_index' => 0,
        'address' => "treasury-$network",
        'available_funds' => $available,
        'native_balance' => $nativeBalance,
    ]);
    Balance::factory()->create([
        'user_id' => $admin->id,
        'network' => $network,
        'amount' => $ownerBalance,
    ]);
    GasPolicy::factory()->create(['network' => Network::nativeKey($network), 'reserve_threshold' => '1.00000000']);

    if ($valuations) {
        UsdValuation::create(['network' => $network, 'conversion_value' => '1.000000']);
        if ($network !== 'bitcoin') {
            UsdValuation::create([
                'network' => $network === 'usdt_trc20' ? 'native_trx' : 'native_eth',
                'conversion_value' => '0.300000',
            ]);
        }
    }

    $payout = TreasuryPayout::create([
        'network' => $network,
        'destination_address' => $destination,
        'amount' => $amount,
        'status' => 'pending',
        'created_by' => $admin->id,
    ]);

    return [$payout, $wallet];
}

function expectBlockedPayout(TreasuryPayout $payout, TreasuryWallet $wallet, PayoutBroadcasterFake $broadcaster, string $message): void
{
    $available = $wallet->available_funds;

    expect((new TreasuryPayoutService($broadcaster))->send($payout))->toBeFalse();
    expect($payout->refresh()->status)->toBe('failed')
        ->and($payout->error_message)->toBe($message)
        ->and($wallet->refresh()->available_funds)->toBe($available)
        ->and($broadcaster->broadcastCalls)->toBe(0);
}

test('treasury payout is broadcast and decrements available funds', function () {
    [$payout, $wallet] = payoutFixture(network: 'bitcoin', amount: '1.50000000', available: '5.00000000', savedAddress: '1BoatSLRHtKNngkdXEeobR76b53LETtpyT');
    $service = new TreasuryPayoutService(new PayoutBroadcasterFake);

    expect($service->send($payout))->toBeTrue();
    expect($payout->refresh()->status)->toBe('sent')
        ->and($payout->tx_hash)->toBe('payout-tx-123')
        ->and($payout->sent_at)->not->toBeNull()
        ->and($wallet->refresh()->available_funds)->toBe('3.49900000');
});

test('treasury payout is rejected when amount exceeds available funds', function () {
    [$payout, $wallet] = payoutFixture(network: 'bitcoin', amount: '1.50000000', available: '1.00000000', savedAddress: '1BoatSLRHtKNngkdXEeobR76b53LETtpyT');

    expectBlockedPayout($payout, $wallet, new PayoutBroadcasterFake, 'Amount exceeds withdrawable profit.');
});

test('treasury payout is blocked when no profit address is saved', function () {
    [$payout, $wallet] = payoutFixture(savedAddress: null);

    expectBlockedPayout($payout, $wallet, new PayoutBroadcasterFake, 'No profit payout address saved for this network.');
});

test('treasury payout is blocked when its destination differs from the saved address', function () {
    [$payout, $wallet] = payoutFixture(destination: 'T222222222222222222222222222222222');

    expectBlockedPayout($payout, $wallet, new PayoutBroadcasterFake, 'Destination does not match the saved profit payout address.');
});

test('treasury payout is blocked when amount exceeds withdrawable profit', function () {
    [$payout, $wallet] = payoutFixture(amount: '20.00000000', ownerBalance: '90.00000000');

    expectBlockedPayout($payout, $wallet, new PayoutBroadcasterFake, 'Amount exceeds withdrawable profit.');
});

test('bitcoin payout is blocked when amount plus fee exceeds withdrawable profit', function () {
    [$payout, $wallet] = payoutFixture(network: 'bitcoin', amount: '9.50000000', available: '10.00000000', savedAddress: '1BoatSLRHtKNngkdXEeobR76b53LETtpyT');
    $broadcaster = new PayoutBroadcasterFake;
    $broadcaster->fee = '1.00000000';

    expectBlockedPayout($payout, $wallet, $broadcaster, 'Amount plus network fee exceeds withdrawable profit.');
});

test('token payout is blocked when it would drain the gas reserve', function () {
    [$payout, $wallet] = payoutFixture(nativeBalance: '2.00000000');
    $broadcaster = new PayoutBroadcasterFake;
    $broadcaster->fee = '1.50000000';

    expectBlockedPayout($payout, $wallet, $broadcaster, 'Gas reserve too low for a payout right now.');
});

test('treasury payout is blocked when USD valuations are unavailable', function () {
    [$payout, $wallet] = payoutFixture(valuations: false);

    expectBlockedPayout($payout, $wallet, new PayoutBroadcasterFake, 'USD prices unavailable — try again in a few minutes.');
});

test('treasury payout is blocked when its fee reaches the configured limit', function () {
    [$payout, $wallet] = payoutFixture(amount: '8.00000000');
    $broadcaster = new PayoutBroadcasterFake;
    $broadcaster->fee = '1.50000000';

    expectBlockedPayout($payout, $wallet, $broadcaster, 'Network fee is 5.63% of the amount — above the 5% limit. Wait for more profit to accumulate.');
});

test('treasury payout preview warns or passes based on fee percentage', function () {
    payoutFixture(amount: '30.00000000');
    $broadcaster = new PayoutBroadcasterFake;
    $broadcaster->fee = '1.50000000';
    $service = new TreasuryPayoutService($broadcaster);

    expect($service->preview('usdt_trc20', '30.00000000')['level'])->toBe('warn');

    $broadcaster->fee = '0.20000000';
    expect($service->preview('usdt_trc20', '30.00000000')['level'])->toBe('ok');
});

test('treasury payout poll confirms and records a gas expense', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $payout = TreasuryPayout::create([
        'network' => 'bitcoin',
        'destination_address' => '1A...',
        'amount' => '1.00000000',
        'status' => 'sent',
        'tx_hash' => 'payout-tx-123',
        'sent_at' => now(),
        'created_by' => $admin->id,
    ]);

    (new TreasuryPayoutService(new PayoutBroadcasterFake))->poll();

    expect($payout->refresh()->status)->toBe('confirmed')
        ->and($payout->confirmed_at)->not->toBeNull();

    $expense = GasExpense::query()->where('expensable_type', TreasuryPayout::class)->where('expensable_id', $payout->id)->first();
    expect($expense)->not->toBeNull()
        ->and($expense->amount)->toBe('0.00010000');
});

test('treasury payout poll marks a failed receipt as failed', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $payout = TreasuryPayout::create([
        'network' => 'bitcoin',
        'destination_address' => '1A...',
        'amount' => '1.00000000',
        'status' => 'sent',
        'tx_hash' => 'payout-tx-123',
        'sent_at' => now(),
        'created_by' => $admin->id,
    ]);
    $broadcaster = new PayoutBroadcasterFake;
    $broadcaster->receiptStatus = 'failed';

    (new TreasuryPayoutService($broadcaster))->poll();

    expect($payout->refresh()->status)->toBe('failed')
        ->and($payout->error_message)->not->toBeNull();
});

test('a payout waits for an ordered rental', function () {
    [$payout] = payoutFixture(amount: '100.00000000', available: '200.00000000');
    GasPolicy::where('network', 'native_trx')->update([
        'energy_mode' => 'rent',
        'rent_max_price_sun' => 90,
        'rent_duration_sec' => 3600,
    ]);
    Http::fake([
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 4160000, 'availableResource' => 100000]]),
        'https://api.tronsave.io/v2/buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['orderId' => 'order-123']]),
    ]);
    $broadcaster = new PayoutBroadcasterFake;
    $broadcaster->transferEnergy = 64285;
    $broadcaster->tronResource = [
        'energy_limit' => 0,
        'energy_used' => 0,
        'bandwidth_limit' => 0,
        'bandwidth_used' => 0,
        'free_bandwidth_limit' => 600,
        'free_bandwidth_used' => 0,
    ];

    expect((new TreasuryPayoutService($broadcaster))->send($payout))->toBeFalse();
    expect($payout->refresh()->status)->toBe('pending')
        ->and($payout->error_message)->toContain('Renting network energy')
        ->and($broadcaster->broadcastCalls)->toBe(0);

    $rental = EnergyRental::sole();
    expect($rental->purpose)->toBe('payout')
        ->and($rental->receiver_address)->toBe('treasury-usdt_trc20')
        ->and($rental->purposable_type)->toBe($payout->getMorphClass())
        ->and($rental->purposable_id)->toBe($payout->id)
        ->and($rental->status)->toBe('ordered');
});

test('the payout sends once the rental fills', function () {
    [$payout] = payoutFixture(amount: '100.00000000', available: '200.00000000');
    GasPolicy::where('network', 'native_trx')->update([
        'energy_mode' => 'rent',
        'rent_max_price_sun' => 90,
        'rent_duration_sec' => 3600,
    ]);
    Http::fake();
    EnergyRental::create([
        'network' => 'usdt_trc20',
        'receiver_address' => 'treasury-usdt_trc20',
        'receiver_index' => 0,
        'purpose' => 'payout',
        'purposable_type' => $payout->getMorphClass(),
        'purposable_id' => $payout->id,
        'energy' => 65000,
        'duration_sec' => 3600,
        'order_id' => 'order-123',
        'cost_native' => '4.16000000',
        'status' => 'filled',
        'filled_at' => now(),
        'expires_at' => now()->addHour(),
    ]);
    $broadcaster = new PayoutBroadcasterFake;
    $broadcaster->transferEnergy = 64285;
    // The delegation landed: the treasury now holds enough rented energy.
    $broadcaster->tronResource = [
        'energy_limit' => 80000,
        'energy_used' => 0,
        'bandwidth_limit' => 0,
        'bandwidth_used' => 0,
        'free_bandwidth_limit' => 600,
        'free_bandwidth_used' => 0,
    ];

    expect((new TreasuryPayoutService($broadcaster))->send($payout))->toBeTrue();
    expect($payout->refresh()->status)->toBe('sent')
        ->and($payout->tx_hash)->toBe('payout-tx-123')
        ->and($broadcaster->broadcastCalls)->toBe(1);
});

test('poll restores available funds when the payout receipt fails', function () {
    [$payout, $wallet] = payoutFixture(amount: '10.00000000', available: '100.00000000');
    $broadcaster = new PayoutBroadcasterFake;
    $service = new TreasuryPayoutService($broadcaster);

    expect($service->send($payout))->toBeTrue();
    expect($wallet->refresh()->available_funds)->toBe('90.00000000');

    // The chain rejected the tx — the tokens never left, so the debit comes back.
    $broadcaster->receiptStatus = 'failed';
    $service->poll();

    expect($payout->refresh()->status)->toBe('failed')
        ->and($payout->error_message)->toBe('Receipt failed')
        ->and($wallet->refresh()->available_funds)->toBe('100.00000000');
});

test('send clears a stale error message on success', function () {
    [$payout] = payoutFixture();
    $payout->update(['error_message' => 'Renting network energy — retry in a minute.']);

    expect((new TreasuryPayoutService(new PayoutBroadcasterFake))->send($payout))->toBeTrue();
    expect($payout->refresh()->status)->toBe('sent')
        ->and($payout->error_message)->toBeNull();
});
