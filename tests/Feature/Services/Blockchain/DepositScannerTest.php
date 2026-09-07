<?php

use App\Events\DepositPending;
use App\Models\BlockchainScanState;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class FakeBlockchainProvider implements BlockchainProvider
{
    /** @var array<int, BlockchainTransaction> */
    public array $transactions = [];

    public bool $fails = false;

    public int $calls = 0;

    public function __construct(private readonly string $networkName) {}

    public function fetchTransactions(array $addresses): array
    {
        $this->calls++;

        if ($this->fails) {
            throw new RuntimeException('Provider failed');
        }

        return $this->transactions;
    }

    public function network(): string
    {
        return $this->networkName;
    }
}

beforeEach(function () {
    config([
        'blockchain.scan_intervals' => [
            'bitcoin' => 0,
            'usdt_trc20' => 0,
            'usdt_erc20' => 0,
        ],
    ]);
});

function createScanner(string $network, array $transactions): DepositScanner
{
    $provider = new FakeBlockchainProvider($network);
    $provider->transactions = $transactions;

    return new DepositScanner([$provider]);
}

test('it creates a pending deposit for an unconfirmed transaction', function () {
    Event::fake([DepositPending::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
        'address' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
    ]);

    $scanner = createScanner('bitcoin', [
        new BlockchainTransaction(
            network: 'bitcoin',
            txHash: 'tx-pending-create',
            toAddress: '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
            amount: '0.50000000',
            confirmations: 1,
        ),
    ]);

    $scanner->scan();

    $deposit = Deposit::query()->where('tx_hash', 'tx-pending-create')->first();
    expect($deposit)->not->toBeNull();
    expect($deposit->status)->toBe('pending');
    expect($deposit->gross_amount)->toBe('0.50000000');
    expect($deposit->user_id)->toBe($owner->id);

    Event::assertDispatched(DepositPending::class, fn (DepositPending $event) => $event->deposit->id === $deposit->id);
});

test('it updates confirmation count and keeps the deposit pending at the threshold', function () {
    Event::fake([DepositPending::class]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
        'address' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
    ]);

    $scanner = createScanner('bitcoin', [
        new BlockchainTransaction(
            network: 'bitcoin',
            txHash: 'tx-pending-threshold',
            toAddress: '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
            amount: '1.00000000',
            confirmations: 1,
        ),
    ]);

    $scanner->scan();

    $first = Deposit::query()->where('tx_hash', 'tx-pending-threshold')->first();
    expect($first->status)->toBe('pending');
    expect($first->confirmation_count)->toBe(1);

    Event::assertDispatched(DepositPending::class, 1);
    Event::fake([DepositPending::class]);

    $scanner2 = createScanner('bitcoin', [
        new BlockchainTransaction(
            network: 'bitcoin',
            txHash: 'tx-pending-threshold',
            toAddress: '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
            amount: '1.00000000',
            confirmations: 3,
        ),
    ]);

    $scanner2->scan();

    $first->refresh();
    expect($first->status)->toBe('pending');
    expect($first->confirmation_count)->toBe(3);

    Event::assertNotDispatched(DepositPending::class);
});

test('it ignores transactions sent to addresses that are not watched', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
        'address' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
    ]);

    $scanner = createScanner('bitcoin', [
        new BlockchainTransaction(
            network: 'bitcoin',
            txHash: 'tx-unknown',
            toAddress: '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2',
            amount: '2.00000000',
            confirmations: 5,
        ),
    ]);

    $scanner->scan();

    expect(Deposit::query()->count())->toBe(0);
});

test('it does not duplicate deposits for the same address and transaction hash', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
        'address' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
    ]);

    $tx = new BlockchainTransaction(
        network: 'bitcoin',
        txHash: 'tx-duplicate',
        toAddress: '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
        amount: '0.10000000',
        confirmations: 1,
    );

    createScanner('bitcoin', [$tx])->scan();
    createScanner('bitcoin', [$tx])->scan();

    expect(Deposit::query()->count())->toBe(1);
});

test('it matches ethereum addresses case-insensitively', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_erc20',
        'address' => '0xA0b86a33E6441E6C7D3D4B4e5F6a7B8c9D0e1F2a',
    ]);

    $scanner = createScanner('usdt_erc20', [
        new BlockchainTransaction(
            network: 'usdt_erc20',
            txHash: 'eth-tx',
            toAddress: '0xa0b86a33e6441e6c7d3d4b4e5f6a7b8c9d0e1f2a',
            amount: '50.000000',
            confirmations: 15,
            tokenContract: '0xdAC17F958D2ee523a2206206994597C13D831ec7',
        ),
    ]);

    $scanner->scan();

    expect(Deposit::query()->count())->toBe(1);
});

test('it logs a failed network and continues scanning other networks', function () {
    Log::spy();
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
        'address' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
    ]);
    $erc20Address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_erc20',
        'address' => '0xA0b86a33E6441E6C7D3D4B4e5F6a7B8c9D0e1F2a',
    ]);
    $failedProvider = new FakeBlockchainProvider('bitcoin');
    $failedProvider->fails = true;
    $workingProvider = new FakeBlockchainProvider('usdt_erc20');
    $workingProvider->transactions = [new BlockchainTransaction(
        network: 'usdt_erc20',
        txHash: 'successful-network-tx',
        toAddress: $erc20Address->address,
        amount: '10.000000',
        confirmations: 15,
    )];

    (new DepositScanner([$failedProvider, $workingProvider]))->scan();

    expect(Deposit::query()->where('tx_hash', 'successful-network-tx')->exists())->toBeTrue();
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Blockchain deposit scan failed.'
        && $context['network'] === 'bitcoin'
        && $context['exception'] instanceof RuntimeException);
});

test('it persistently schedules each network at its configured cadence', function () {
    config(['blockchain.scan_intervals.bitcoin' => 15]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bitcoin',
    ]);
    $provider = new FakeBlockchainProvider('bitcoin');
    $scanner = new DepositScanner([$provider]);

    $scanner->scan();
    $scanner->scan();

    expect($provider->calls)->toBe(1)
        ->and(BlockchainScanState::query()->where('network', 'bitcoin')->value('next_scan_at'))->not->toBeNull();

    $this->travel(15)->minutes();
    $scanner->scan();

    expect($provider->calls)->toBe(2);
});

test('it cools down a failed provider without affecting other networks and resets after recovery', function () {
    config([
        'blockchain.provider_backoff.base_minutes' => 2,
        'blockchain.provider_backoff.max_minutes' => 30,
    ]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin']);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    $failed = new FakeBlockchainProvider('bitcoin');
    $failed->fails = true;
    $working = new FakeBlockchainProvider('usdt_trc20');
    $scanner = new DepositScanner([$failed, $working]);

    $scanner->scan();
    $scanner->scan();

    expect($failed->calls)->toBe(1)
        ->and($working->calls)->toBe(2)
        ->and(BlockchainScanState::query()->where('network', 'bitcoin')->value('consecutive_failures'))->toBe(1);

    $failed->fails = false;
    $this->travel(2)->minutes();
    $scanner->scan();

    $state = BlockchainScanState::query()->where('network', 'bitcoin')->first();
    expect($failed->calls)->toBe(2)
        ->and($state->consecutive_failures)->toBe(0)
        ->and($state->cooldown_until)->toBeNull();
});

test('it skips a network when no provider is configured', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
        'address' => 'TTestAddress1234567890',
    ]);

    $scanner = new DepositScanner([]);
    $scanner->scan();

    expect(Deposit::query()->count())->toBe(0);
});
