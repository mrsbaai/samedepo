<?php

declare(strict_types=1);

use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasExpense;
use App\Models\GasTopup;
use App\Models\LedgerEntry;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\TreasurySweepService;

class RecoveryBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $tokenBalance = '0.00000000';

    public ?string $nativeBalance = '23.57000000';

    public ?string $treasuryBalance = '100.00000000';

    public ?string $topupHash = 'recovery-tx-1';

    public string $receiptStatus = 'confirmed';

    public int $receiptConfirmations = 20;

    public string $receiptFee = '0.27000000';

    public array $broadcastTopUpCalls = [];

    public int $balanceCalls = 0;

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
        $this->balanceCalls++;

        return $index === 0 ? $this->treasuryBalance : $this->nativeBalance;
    }

    public function getTokenBalance(string $network, int $index): ?string
    {
        $this->balanceCalls++;

        return $this->tokenBalance;
    }

    public function getTronResource(int $index): ?array
    {
        return null;
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        return [
            'status' => $this->receiptStatus,
            'fee' => $this->receiptFee,
            'confirmations' => $this->receiptConfirmations,
        ];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return '6.77350000';
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        $this->broadcastTopUpCalls[] = compact('network', 'sourceIndex', 'destinationIndex', 'amount', 'fee');

        return $this->topupHash;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }
}

function recoveryFixture(array $options = []): array
{
    config(['blockchain.gas_recovery.min_native.usdt_trc20' => '5']);

    $wallet = TreasuryWallet::factory()->create([
        'network' => 'usdt_trc20',
        'derivation_index' => 0,
        'address' => 'TTreasury',
    ]);
    $address = DepositAddress::factory()->create([
        'network' => 'usdt_trc20',
        'derivation_index' => 3,
        'address' => 'TDepositStranded',
    ]);

    if ($options['toppedUp'] ?? true) {
        // Stranded TRX can only exist where a confirmed top-up landed.
        GasTopup::create([
            'treasury_wallet_id' => $wallet->id,
            'network' => 'usdt_trc20',
            'kind' => 'topup',
            'recipient_address' => 'TDepositStranded',
            'recipient_index' => 3,
            'amount' => '8.00000000',
            'tx_hash' => 'topup-historical',
            'status' => 'confirmed',
            'confirmed_at' => now()->subDay(),
            'is_open' => 'done',
        ]);
    }

    $broadcaster = new RecoveryBroadcasterFake;
    $broadcaster->tokenBalance = array_key_exists('tokenBalance', $options) ? $options['tokenBalance'] : '0.00000000';
    $broadcaster->nativeBalance = array_key_exists('nativeBalance', $options) ? $options['nativeBalance'] : '23.57000000';
    $broadcaster->receiptStatus = array_key_exists('receiptStatus', $options) ? $options['receiptStatus'] : 'confirmed';

    return [new GasTreasuryService($broadcaster), $broadcaster, $wallet, $address];
}

test('it recovers stranded gas when the address is empty and above the threshold', function () {
    [$service, $broadcaster] = recoveryFixture(['nativeBalance' => '23.57000000']);

    $service->recoverStrandedGas();

    expect($broadcaster->broadcastTopUpCalls)->toHaveCount(1);
    expect($broadcaster->broadcastTopUpCalls[0])->toMatchArray([
        'network' => 'usdt_trc20',
        'sourceIndex' => 3,
        'destinationIndex' => 0,
        'amount' => '23.07000000',
        'fee' => '0.30000000',
    ]);

    $topup = GasTopup::query()->where('kind', 'recovery')->sole();
    expect($topup->kind)->toBe('recovery')
        ->and($topup->recipient_address)->toBe('TTreasury')
        ->and($topup->status)->toBe('confirmed')
        ->and(GasExpense::query()->where('gas_topup_id', $topup->id)->exists())->toBeTrue();
});

test('it leaves the recovery reserve', function () {
    [$service, $broadcaster] = recoveryFixture(['nativeBalance' => '5.40000000']);

    $service->recoverStrandedGas();

    expect($broadcaster->broadcastTopUpCalls)->toHaveCount(1);
    expect($broadcaster->broadcastTopUpCalls[0]['amount'])->toBe('4.90000000');
});

test('it skips recovery when the token balance is null', function () {
    [$service, $broadcaster] = recoveryFixture(['tokenBalance' => null]);

    $service->recoverStrandedGas();

    expect($broadcaster->broadcastTopUpCalls)->toBeEmpty();
    expect(GasTopup::query()->where('kind', 'recovery')->count())->toBe(0);
});

test('it skips recovery while tokens remain', function () {
    [$service, $broadcaster] = recoveryFixture(['tokenBalance' => '5.00000000']);

    $service->recoverStrandedGas();

    expect($broadcaster->broadcastTopUpCalls)->toBeEmpty();
    expect(GasTopup::query()->where('kind', 'recovery')->count())->toBe(0);
});

test('it skips recovery below the native threshold', function () {
    [$service, $broadcaster] = recoveryFixture(['nativeBalance' => '4.99000000']);

    $service->recoverStrandedGas();

    expect($broadcaster->broadcastTopUpCalls)->toBeEmpty();
    expect(GasTopup::query()->where('kind', 'recovery')->count())->toBe(0);
});

test('it skips addresses with unswept deposits', function () {
    [$service, $broadcaster, $wallet, $address] = recoveryFixture();
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $address->customer_id,
        'user_id' => $address->customer->user_id,
        'network' => 'usdt_trc20',
        'status' => 'credited',
    ]);
    // The deposit factory always spawns its own deposit address; drop the
    // orphan so only the fixture address is a recovery candidate.
    DepositAddress::query()->whereKeyNot($address->id)->delete();

    $service->recoverStrandedGas();

    expect($broadcaster->broadcastTopUpCalls)->toBeEmpty();
    expect(GasTopup::query()->where('kind', 'recovery')->count())->toBe(0);
});

test('it does not stack recoveries while a row is open', function () {
    [$service, $broadcaster, $wallet] = recoveryFixture();
    $open = GasTopup::create([
        'treasury_wallet_id' => $wallet->id,
        'network' => 'usdt_trc20',
        'kind' => 'recovery',
        'recipient_address' => 'TTreasury',
        'recipient_index' => 0,
        'amount' => '20.00000000',
        'tx_hash' => 'recovery-tx-open',
        'status' => 'broadcast',
        'broadcasted_at' => now(),
        'is_open' => 'open',
    ]);

    $service->recoverStrandedGas();

    expect($broadcaster->broadcastTopUpCalls)->toBeEmpty();
    expect(GasTopup::query()->where('kind', 'recovery')->count())->toBe(1);

    $service->pollTopups();
    $open->refresh();
    expect($open->status)->toBe('confirmed')
        ->and($open->is_open)->toBe((string) $open->id);

    $service->recoverStrandedGas();
    expect($broadcaster->broadcastTopUpCalls)->toHaveCount(1);
    expect(GasTopup::query()->where('kind', 'recovery')->count())->toBe(2);
});

test('it ignores addresses that were never topped up', function () {
    [$service, $broadcaster] = recoveryFixture(['toppedUp' => false]);

    $service->recoverStrandedGas();

    expect($broadcaster->balanceCalls)->toBe(0)
        ->and($broadcaster->broadcastTopUpCalls)->toBeEmpty()
        ->and(GasTopup::query()->where('kind', 'recovery')->count())->toBe(0);
});

test('it skips an address with an open top-up row', function () {
    [$service, $broadcaster, $wallet, $address] = recoveryFixture();
    GasTopup::create([
        'treasury_wallet_id' => $wallet->id,
        'network' => 'usdt_trc20',
        'kind' => 'topup',
        'recipient_address' => 'TDepositStranded',
        'recipient_index' => 3,
        'amount' => '8.00000000',
        'tx_hash' => 'topup-open',
        'status' => 'broadcast',
        'broadcasted_at' => now(),
        'is_open' => 'open',
    ]);

    $service->recoverStrandedGas();

    expect($broadcaster->broadcastTopUpCalls)->toBeEmpty();
    expect(GasTopup::query()->where('kind', 'recovery')->count())->toBe(0);
});

test('recovery rows are never billed to an owner', function () {
    config(['blockchain.gas_recovery.min_native.usdt_trc20' => '5']);
    $wallet = TreasuryWallet::factory()->create([
        'network' => 'usdt_trc20',
        'derivation_index' => 0,
        'address' => 'TTreasury',
    ]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
        'derivation_index' => 7,
        'address' => 'TDepositOwner',
    ]);
    UsdValuation::updateOrCreate(['network' => 'native_trx'], ['conversion_value' => '0.33']);
    UsdValuation::updateOrCreate(['network' => 'usdt_trc20'], ['conversion_value' => '1.00']);
    Balance::create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => '50.00000000']);

    $topup = GasTopup::create([
        'treasury_wallet_id' => $wallet->id,
        'network' => 'usdt_trc20',
        'kind' => 'topup',
        'recipient_address' => 'TDepositOwner',
        'recipient_index' => 7,
        'amount' => '12.72850000',
        'tx_hash' => 'topup-1',
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'is_open' => 'done',
    ]);
    GasExpense::create([
        'gas_topup_id' => $topup->id,
        'expensable_type' => GasTopup::class,
        'expensable_id' => $topup->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'topup-1',
        'amount' => '0.30000000',
    ]);
    $recovery = GasTopup::create([
        'treasury_wallet_id' => $wallet->id,
        'network' => 'usdt_trc20',
        'kind' => 'recovery',
        'recipient_address' => 'TTreasury',
        'recipient_index' => 0,
        'amount' => '23.07000000',
        'tx_hash' => 'recovery-1',
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'is_open' => 'done',
    ]);
    GasExpense::create([
        'gas_topup_id' => $recovery->id,
        'expensable_type' => GasTopup::class,
        'expensable_id' => $recovery->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'recovery-1',
        'amount' => '0.27000000',
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
        'network' => 'usdt_trc20',
        'amount' => '10.00000000',
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'fee_recovered_at' => null,
    ]);

    (new TreasurySweepService(new RecoveryBroadcasterFake))->billConsolidationCosts();

    // Only the top-up is attributable: 12.7285 + 0.30 = 13.0285 TRX * 0.33 = 4.299405 USDT.
    // The recovery's 23.07 TRX never enters the ledger entry.
    expect((string) LedgerEntry::query()->where('reason', 'consolidation_fee')->value('amount'))
        ->toBe('-4.29940500')
        ->and((string) Balance::query()->where('user_id', $owner->id)->value('amount'))->toBe('45.70059500')
        ->and($sweep->refresh()->fee_recovered_at)->not->toBeNull()
        ->and($topup->refresh()->fee_recovered_at)->not->toBeNull()
        ->and($recovery->refresh()->fee_recovered_at)->toBeNull();
});

test('a failed recovery is not a failed operation', function () {
    [$service, $broadcaster, $wallet] = recoveryFixture();
    GasTopup::create([
        'treasury_wallet_id' => $wallet->id,
        'network' => 'usdt_trc20',
        'kind' => 'recovery',
        'recipient_address' => 'TTreasury',
        'recipient_index' => 0,
        'amount' => '23.07000000',
        'status' => 'failed',
        'error_message' => 'Broadcast failed',
        'is_open' => 'done',
    ]);

    expect(GasTopup::query()->where('status', 'failed')->count())->toBe(1);
    expect(GasTopup::query()->where('status', 'failed')->where('kind', 'topup')->count())->toBe(0);
});
