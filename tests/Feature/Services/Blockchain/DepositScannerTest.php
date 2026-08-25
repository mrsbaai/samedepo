<?php

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;

class FakeBlockchainProvider implements BlockchainProvider
{
    /** @var array<int, BlockchainTransaction> */
    public array $transactions = [];

    public function __construct(private readonly string $networkName) {}

    public function fetchTransactions(array $addresses): array
    {
        return $this->transactions;
    }

    public function network(): string
    {
        return $this->networkName;
    }
}

function createScanner(string $network, array $transactions): DepositScanner
{
    $provider = new FakeBlockchainProvider($network);
    $provider->transactions = $transactions;

    return new DepositScanner([$provider]);
}

test('it creates a detected deposit for an unconfirmed transaction', function () {
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
            txHash: 'tx-detected',
            toAddress: '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
            amount: '0.50000000',
            confirmations: 1,
        ),
    ]);

    $scanner->scan();

    $deposit = Deposit::query()->where('tx_hash', 'tx-detected')->first();
    expect($deposit)->not->toBeNull();
    expect($deposit->status)->toBe('detected');
    expect($deposit->gross_amount)->toBe('0.50000000');
    expect($deposit->user_id)->toBe($owner->id);
});

test('it updates confirmation count and moves the deposit to pending when the threshold is reached', function () {
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
            txHash: 'tx-pending',
            toAddress: '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
            amount: '1.00000000',
            confirmations: 1,
        ),
    ]);

    $scanner->scan();

    $first = Deposit::query()->where('tx_hash', 'tx-pending')->first();
    expect($first->status)->toBe('detected');
    expect($first->confirmation_count)->toBe(1);

    $scanner2 = createScanner('bitcoin', [
        new BlockchainTransaction(
            network: 'bitcoin',
            txHash: 'tx-pending',
            toAddress: '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
            amount: '1.00000000',
            confirmations: 3,
        ),
    ]);

    $scanner2->scan();

    $first->refresh();
    expect($first->status)->toBe('pending');
    expect($first->confirmation_count)->toBe(3);
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
