<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\RemoteBlockchainBroadcaster;
use App\Services\Blockchain\TreasurySweepService;
use Illuminate\Support\Facades\Http;

class BatchSweepBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $hash = 'batch-sweep-tx';

    public string $receiptStatus = 'confirmed';

    public int $broadcasts = 0;

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        $this->broadcasts++;

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
        return '1000.00000000';
    }

    public function getTronResource(int $index): ?array
    {
        return ['energy_limit' => 100000, 'energy_used' => 0, 'bandwidth_limit' => 100000, 'bandwidth_used' => 0];
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        return ['status' => $this->receiptStatus, 'fee' => '0.00100000', 'confirmations' => 3];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return '0.00100000';
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return null;
    }

    public function broadcastPayout(\App\Models\TreasuryPayout $payout): ?string
    {
        return null;
    }
}

function batchAddress(?User $owner = null, string $network = 'bitcoin'): array
{
    $owner ??= User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => $network]);

    return [$owner, $customer, $address];
}

function batchDeposit(User $owner, Customer $customer, DepositAddress $address, string $amount, $creditedAt = null): Deposit
{
    return Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $address->network,
        'gross_amount' => $amount,
        'status' => 'credited',
        'credited_at' => $creditedAt ?? now(),
        'swept_at' => null,
    ]);
}

beforeEach(function () {
    PlatformSettings::instance()->update([
        'sweep_min_usd_bitcoin' => '200.00',
        'sweep_max_age_days' => 30,
    ]);
    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => '100.000000']);
    TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => '0.00000000']);
});

test('a credited deposit below every trigger remains unswept', function () {
    [$owner, $customer, $address] = batchAddress();
    $deposit = batchDeposit($owner, $customer, $address, '1.00000000');

    (new TreasurySweepService(new BatchSweepBroadcasterFake))->sweep();

    expect($deposit->fresh()->swept_at)->toBeNull()
        ->and(TreasurySweep::count())->toBe(0);
});

test('threshold batches one address and confirms every covered deposit', function () {
    [$owner, $customer, $address] = batchAddress();
    $first = batchDeposit($owner, $customer, $address, '1.25000000');
    $second = batchDeposit($owner, $customer, $address, '0.75000000');

    (new TreasurySweepService(new BatchSweepBroadcasterFake))->sweep();

    $sweep = TreasurySweep::sole();
    expect($sweep->deposit_address_id)->toBe($address->id)
        ->and($sweep->deposit_id)->toBeNull()
        ->and($sweep->amount)->toBe('2.00000000')
        ->and($sweep->status)->toBe('confirmed')
        ->and($first->fresh()->swept_at)->not->toBeNull()
        ->and($second->fresh()->swept_at)->not->toBeNull()
        ->and(TreasuryWallet::where('network', 'bitcoin')->value('available_funds'))->toBe('2.00000000');
});

test('age trigger sweeps a lone small deposit', function () {
    [$owner, $customer, $address] = batchAddress();
    $deposit = batchDeposit($owner, $customer, $address, '0.10000000', now()->subDays(30));

    (new TreasurySweepService(new BatchSweepBroadcasterFake))->sweep();

    expect($deposit->fresh()->swept_at)->not->toBeNull()
        ->and(TreasurySweep::sole()->amount)->toBe('0.10000000');
});

test('withdrawal need triggers a sweep for the matching owner and network', function () {
    [$owner, $customer, $address] = batchAddress();
    batchDeposit($owner, $customer, $address, '0.50000000');
    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.00000000',
        'mode' => 'approval',
        'status' => 'approved',
    ]);

    (new TreasurySweepService(new BatchSweepBroadcasterFake))->sweep();

    expect(TreasurySweep::sole()->deposit_address_id)->toBe($address->id);
});

test('different customer addresses are never combined', function () {
    [$firstOwner, $firstCustomer, $firstAddress] = batchAddress();
    [$secondOwner, $secondCustomer, $secondAddress] = batchAddress();
    batchDeposit($firstOwner, $firstCustomer, $firstAddress, '2.00000000');
    batchDeposit($secondOwner, $secondCustomer, $secondAddress, '2.00000000');

    (new TreasurySweepService(new BatchSweepBroadcasterFake))->sweep();

    expect(TreasurySweep::count())->toBe(2)
        ->and(TreasurySweep::pluck('deposit_address_id')->sort()->values()->all())
        ->toBe(collect([$firstAddress->id, $secondAddress->id])->sort()->values()->all());
});

test('rerunning while a batch sweep is open does not create a duplicate', function () {
    [$owner, $customer, $address] = batchAddress();
    batchDeposit($owner, $customer, $address, '2.00000000');
    $broadcaster = new BatchSweepBroadcasterFake;
    $broadcaster->receiptStatus = 'pending';
    $service = new TreasurySweepService($broadcaster);

    $service->sweep();
    $service->sweep();

    expect(TreasurySweep::count())->toBe(1)
        ->and($broadcaster->broadcasts)->toBe(1);
});

test('legacy deposit keyed sweeps still confirm', function () {
    [$owner, $customer, $address] = batchAddress();
    $deposit = batchDeposit($owner, $customer, $address, '1.00000000');
    TreasurySweep::create([
        'deposit_id' => $deposit->id,
        'deposit_address_id' => null,
        'network' => 'bitcoin',
        'amount' => '1.00000000',
        'tx_hash' => 'legacy-tx',
        'status' => 'pending',
    ]);

    (new TreasurySweepService(new BatchSweepBroadcasterFake))->sweep();

    expect($deposit->fresh()->swept_at)->not->toBeNull()
        ->and(TreasurySweep::where('tx_hash', 'legacy-tx')->value('status'))->toBe('confirmed')
        ->and(TreasuryWallet::where('network', 'bitcoin')->value('available_funds'))->toBe('1.00000000');
});

test('remote broadcaster resolves a batch sweep source from its deposit address', function () {
    [, , $address] = batchAddress();
    $address->update(['derivation_index' => 42]);
    $sweep = TreasurySweep::create([
        'deposit_id' => null,
        'deposit_address_id' => $address->id,
        'network' => 'bitcoin',
        'amount' => '2.00000000',
        'status' => 'pending',
    ]);
    Http::fake([
        'https://signer.test/fee' => Http::response(['data' => ['fee' => '0.00010000']]),
        'https://signer.test/sweep' => Http::response(['data' => ['tx_hash' => 'remote-batch-tx']]),
    ]);

    $hash = (new RemoteBlockchainBroadcaster('https://signer.test', 'secret'))->broadcastSweep($sweep);

    expect($hash)->toBe('remote-batch-tx');
    Http::assertSent(fn ($request) => $request->url() === 'https://signer.test/sweep'
        && $request['source_index'] === 42
        && $request['fee'] === '0.00010000'
        && $request['amount'] === '2.00010000');
});

test('a deposit credited after a pending batch sweep is not marked swept on confirmation', function () {
    [$owner, $customer, $address] = batchAddress();
    $first = batchDeposit($owner, $customer, $address, '2.00000000');

    $broadcaster = new BatchSweepBroadcasterFake;
    $broadcaster->receiptStatus = 'pending';
    $service = new TreasurySweepService($broadcaster);
    $service->sweep();

    $second = batchDeposit($owner, $customer, $address, '1.00000000');

    $broadcaster->receiptStatus = 'confirmed';
    $service->sweep();

    expect($first->fresh()->swept_at)->not->toBeNull()
        ->and($second->fresh()->swept_at)->toBeNull();
});
