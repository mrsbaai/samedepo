<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\EnergyRental;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\EstimatesTransferFee;
use App\Services\Blockchain\GasTreasuryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnergyRentalBroadcasterFake implements BlockchainBroadcaster, EstimatesTransferFee
{
    public ?string $transferFee = '6.77350000';

    public ?string $nativeTopupFee = '0.27000000';

    public ?string $recipientBalance = '0.00000000';

    public ?string $treasuryBalance = '30.00000000';

    public ?string $topupHash = 'topup-tx-123';

    public array $topupCalls = [];

    public ?array $tronResource = [
        'energy_limit' => 0,
        'energy_used' => 0,
        'bandwidth_limit' => 0,
        'bandwidth_used' => 0,
        'free_bandwidth_limit' => 600,
        'free_bandwidth_used' => 0,
    ];

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
        return $index === 0 ? $this->treasuryBalance : $this->recipientBalance;
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
        return ['status' => 'confirmed', 'fee' => '0.27000000', 'confirmations' => 20];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return $tokenTransfer ? $this->transferFee : $this->nativeTopupFee;
    }

    public function estimateTransferFee(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?string
    {
        return $tokenTransfer ? $this->transferFee : $this->nativeTopupFee;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        $this->topupCalls[] = compact('network', 'sourceIndex', 'destinationIndex', 'amount', 'fee');

        return $this->topupHash;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }
}

function tronSaveFakes(array $overrides = []): array
{
    $map = [
        'https://api.tronsave.io/v2/user-info' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['id' => 'acc', 'balance' => '50000000', 'representAddress' => 'TRep', 'depositAddress' => 'TDep']]),
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 4160000, 'availableResource' => 100000]]),
        'https://api.tronsave.io/v2/buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['orderId' => 'order-123']]),
        'https://api.tronsave.io/v2/order/*' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['id' => 'order-123', 'fulfilledPercent' => 100, 'payoutAmount' => 4160000, 'price' => 64, 'delegates' => [['delegator' => 'TDel', 'amount' => 77142, 'txid' => 'abc123']]]]),
    ];

    return array_merge($map, $overrides);
}

function rentalFixture(array $options = []): array
{
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20']);
    TreasuryWallet::factory()->create([
        'network' => 'usdt_trc20',
        'derivation_index' => 0,
        'address' => 'TTreasury',
    ]);
    GasPolicy::factory()->create([
        'network' => 'usdt_trc20',
        'reserve_threshold' => '10.00000000',
        'top_up_amount' => '1.00000000',
        'max_top_up' => '20.00000000',
        'energy_mode' => $options['energy_mode'] ?? 'rent',
        'rent_max_price_sun' => 90,
        'rent_duration_sec' => 3600,
    ]);

    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
        'derivation_index' => 3,
        'address' => 'TDeposit3',
    ]);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '10.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);
    $sweep = TreasurySweep::create([
        'deposit_id' => $deposit->id,
        'deposit_address_id' => $address->id,
        'network' => 'usdt_trc20',
        'amount' => '10.00000000',
        'status' => 'pending',
    ]);

    $broadcaster = new EnergyRentalBroadcasterFake;
    $broadcaster->tronResource = $options['tronResource'] ?? $broadcaster->tronResource;

    Http::fake(tronSaveFakes($options['fakes'] ?? []));

    return [new GasTreasuryService($broadcaster), $broadcaster, $sweep];
}

test('rent mode orders energy instead of topping up', function () {
    [$service, , $sweep] = rentalFixture();

    expect($service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep))->toBeFalse();

    $rental = EnergyRental::sole();
    expect($rental->status)->toBe('ordered')
        ->and($rental->order_id)->toBe('order-123')
        ->and($rental->purpose)->toBe('sweep')
        ->and($rental->purposable_type)->toBe($sweep->getMorphClass())
        ->and($rental->purposable_id)->toBe($sweep->id)
        ->and($rental->energy)->toBe(77142)
        ->and($rental->expires_at->greaterThan(now()->addMinutes(59)))->toBeTrue()
        ->and($rental->expires_at->lessThan(now()->addMinutes(61)))->toBeTrue();
    expect(GasTopup::count())->toBe(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/buy-resource')
        && $request->data()['receiver'] === 'TDeposit3');
});

test('an already provisioned address needs no order', function () {
    [$service, , $sweep] = rentalFixture(['tronResource' => [
        'energy_limit' => 80000,
        'energy_used' => 0,
        'bandwidth_limit' => 0,
        'bandwidth_used' => 0,
        'free_bandwidth_limit' => 600,
        'free_bandwidth_used' => 0,
    ]]);

    expect($service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep))->toBeTrue();
    expect(EnergyRental::count())->toBe(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/buy-resource'));
});

test('a second tick waits while an order is open', function () {
    [$service, , $sweep] = rentalFixture();
    EnergyRental::create([
        'network' => 'usdt_trc20',
        'receiver_address' => 'TDeposit3',
        'receiver_index' => 3,
        'purpose' => 'sweep',
        'energy' => 77142,
        'duration_sec' => 3600,
        'order_id' => 'order-123',
        'status' => 'ordered',
        'ordered_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    expect($service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep))->toBeFalse();
    expect(EnergyRental::count())->toBe(1);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/buy-resource'));
});

test('pollRentals fills the order and records the expense', function () {
    [, , $sweep] = rentalFixture();
    $rental = EnergyRental::create([
        'network' => 'usdt_trc20',
        'receiver_address' => 'TDeposit3',
        'receiver_index' => 3,
        'purpose' => 'sweep',
        'purposable_type' => $sweep->getMorphClass(),
        'purposable_id' => $sweep->id,
        'energy' => 77142,
        'duration_sec' => 3600,
        'order_id' => 'order-123',
        'status' => 'ordered',
        'ordered_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    (new GasTreasuryService(new EnergyRentalBroadcasterFake))->pollRentals();

    $rental->refresh();
    expect($rental->status)->toBe('filled')
        ->and($rental->cost_native)->toBe('4.16000000')
        ->and($rental->unit_price_sun)->toBe(64)
        ->and($rental->filled_at)->not->toBeNull();

    $expense = GasExpense::sole();
    expect($expense->energy_rental_id)->toBe($rental->id)
        ->and($expense->amount)->toBe('4.16000000')
        ->and($expense->tx_hash)->toBe('abc123')
        ->and($expense->expensable_type)->toBe($sweep->getMorphClass())
        ->and($expense->expensable_id)->toBe($sweep->id);

    (new GasTreasuryService(new EnergyRentalBroadcasterFake))->pollRentals();
    expect(GasExpense::count())->toBe(1);
});

test('an unfilled order fails after ten minutes and the next tick burns', function () {
    [$service, $broadcaster, $sweep] = rentalFixture(['fakes' => [
        'https://api.tronsave.io/v2/order/*' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['id' => 'order-123', 'fulfilledPercent' => 40]]),
    ]]);
    $rental = EnergyRental::create([
        'network' => 'usdt_trc20',
        'receiver_address' => 'TDeposit3',
        'receiver_index' => 3,
        'purpose' => 'sweep',
        'purposable_type' => $sweep->getMorphClass(),
        'purposable_id' => $sweep->id,
        'energy' => 77142,
        'duration_sec' => 3600,
        'order_id' => 'order-123',
        'status' => 'ordered',
        'ordered_at' => now()->subMinutes(11),
        'expires_at' => now()->addHour(),
    ]);

    $service->pollRentals();

    expect($rental->refresh()->status)->toBe('failed')
        ->and($rental->error_message)->not->toBeNull();

    $service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep);

    expect(GasTopup::count())->toBe(1)
        ->and($broadcaster->topupCalls)->toHaveCount(1);
});

test('a price above the cap falls back to burn', function () {
    Log::spy();
    [$service, $broadcaster, $sweep] = rentalFixture(['fakes' => [
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 95, 'durationSec' => 3600, 'estimateTrx' => 4160000, 'availableResource' => 100000]]),
    ]]);

    $service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep);

    expect(EnergyRental::count())->toBe(0)
        ->and(GasTopup::count())->toBe(1)
        ->and($broadcaster->topupCalls)->toHaveCount(1);
    Log::shouldHaveReceived('warning')
        ->with('energy.rent_fallback', Mockery::on(fn ($context) => $context['reason'] === 'price_cap'));
});

test('insufficient market energy falls back to burn', function () {
    Log::spy();
    [$service, , $sweep] = rentalFixture(['fakes' => [
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 4160000, 'availableResource' => 1000]]),
    ]]);

    $service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep);

    expect(EnergyRental::count())->toBe(0)
        ->and(GasTopup::count())->toBe(1);
    Log::shouldHaveReceived('warning')
        ->with('energy.rent_fallback', Mockery::on(fn ($context) => $context['reason'] === 'market_short'));
});

test('an unavailable estimate falls back to burn', function () {
    Log::spy();
    [$service, , $sweep] = rentalFixture(['fakes' => [
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response('down', 500),
    ]]);

    $service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep);

    expect(EnergyRental::count())->toBe(0)
        ->and(GasTopup::count())->toBe(1);
    Log::shouldHaveReceived('warning')
        ->with('energy.rent_fallback', Mockery::on(fn ($context) => $context['reason'] === 'estimate_unavailable'));
});

test('a rental that costs as much as burning falls back', function () {
    Log::spy();
    [$service, , $sweep] = rentalFixture(['fakes' => [
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 8000000, 'availableResource' => 100000]]),
    ]]);

    $service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep);

    expect(EnergyRental::count())->toBe(0)
        ->and(GasTopup::count())->toBe(1);
    Log::shouldHaveReceived('warning')
        ->with('energy.rent_fallback', Mockery::on(fn ($context) => $context['reason'] === 'not_cheaper_than_burn'));
});

test('a failed order does not block rentals for a null purposable', function () {
    // The previous_order_unfilled guard is scoped to a specific job; a stale
    // failed order must not permanently block renting for the address.
    [$service, $broadcaster] = rentalFixture();

    EnergyRental::create([
        'network' => 'usdt_trc20',
        'receiver_address' => 'TDeposit3',
        'receiver_index' => 3,
        'purpose' => 'sweep',
        'energy' => 80000,
        'duration_sec' => 3600,
        'status' => 'failed',
        'ordered_at' => now()->subMinutes(20),
    ]);

    expect($service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3'))->toBeFalse()
        ->and(EnergyRental::where('status', 'ordered')->count())->toBe(1)
        ->and(GasTopup::count())->toBe(0)
        ->and($broadcaster->topupCalls)->toHaveCount(0);
});

test('burn mode keeps phase one behaviour', function () {
    [$service, $broadcaster, $sweep] = rentalFixture(['energy_mode' => 'burn']);

    expect($service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep))->toBeTrue();

    $topup = GasTopup::sole();
    expect($topup->amount)->toBe('8.12820000');
    expect($broadcaster->topupCalls)->toHaveCount(1);
    expect(EnergyRental::count())->toBe(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.tronsave.io'));
});

test('a bandwidth shortfall provisions only a small top-up', function () {
    [$service, $broadcaster, $sweep] = rentalFixture(['tronResource' => [
        'energy_limit' => 80000,
        'energy_used' => 0,
        'bandwidth_limit' => 0,
        'bandwidth_used' => 0,
        'free_bandwidth_limit' => 600,
        'free_bandwidth_used' => 600,
    ]]);

    expect($service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep))->toBeFalse();

    $topup = GasTopup::sole();
    expect($topup->amount)->toBe('0.40000000');
    expect($broadcaster->topupCalls)->toHaveCount(1)
        ->and($broadcaster->topupCalls[0]['amount'])->toBe('0.40000000')
        ->and($broadcaster->topupCalls[0]['fee'])->toBe('0.30000000');
    expect(EnergyRental::count())->toBe(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/buy-resource'));
});

test('no trx is sent to the deposit address in rent mode', function () {
    // Provisioned address: zero top-ups.
    [$service, $broadcaster, $sweep] = rentalFixture(['tronResource' => [
        'energy_limit' => 80000,
        'energy_used' => 0,
        'bandwidth_limit' => 0,
        'bandwidth_used' => 0,
        'free_bandwidth_limit' => 600,
        'free_bandwidth_used' => 0,
    ]]);
    $service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep);
    expect($broadcaster->topupCalls)->toHaveCount(0);

    // Open order while unprovisioned: still zero — the fee-sized top-up path is never reached.
    $broadcaster->tronResource = [
        'energy_limit' => 0,
        'energy_used' => 0,
        'bandwidth_limit' => 0,
        'bandwidth_used' => 0,
        'free_bandwidth_limit' => 600,
        'free_bandwidth_used' => 0,
    ];
    EnergyRental::create([
        'network' => 'usdt_trc20',
        'receiver_address' => 'TDeposit3',
        'receiver_index' => 3,
        'purpose' => 'sweep',
        'purposable_type' => $sweep->getMorphClass(),
        'purposable_id' => $sweep->id,
        'energy' => 77142,
        'duration_sec' => 3600,
        'order_id' => 'order-123',
        'status' => 'ordered',
        'ordered_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    expect($service->ensureGasForSweep('usdt_trc20', 3, 'TDeposit3', $sweep))->toBeFalse();
    expect($broadcaster->topupCalls)->toHaveCount(0);
});
