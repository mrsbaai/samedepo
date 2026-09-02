<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasExpense;
use App\Models\LedgerEntry;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\WithdrawalProcessor;

class WithdrawalFeeBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $hash = 'withdrawal-tx-123';

    public ?string $fee = '0.25000000';

    public ?string $nativeBalance = '1000.00000000';

    public ?string $topupHash = 'topup-tx-123';

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return 'sweep-tx-123';
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        return $this->hash;
    }

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        return $this->fee;
    }

    public function getNativeBalance(string $network, int $index): ?string
    {
        return $this->nativeBalance;
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
            'status' => 'confirmed',
            'fee' => '0.00010000',
            'confirmations' => 3,
        ];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return '0.00100000';
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

function feeTestProcessor(?string $hash = 'withdrawal-tx-123', ?string $fee = null): array
{
    $broadcaster = new WithdrawalFeeBroadcasterFake;
    $broadcaster->hash = $hash;
    if ($fee !== null) {
        $broadcaster->fee = $fee;
    }

    return [new WithdrawalProcessor($broadcaster), $broadcaster];
}

function feeTestWithdrawal(string $network, string $gross, array $attributes = []): array
{
    $owner = User::factory()->create(['role' => 'owner']);

    TreasuryWallet::firstOrCreate(
        ['network' => $network],
        [
            'derivation_index' => 0,
            'address' => 'treasury-'.$network,
            'available_funds' => '1000.00000000',
            'native_balance' => '1000.00000000',
        ],
    );

    $withdrawal = Withdrawal::factory()->create(array_merge([
        'user_id' => $owner->id,
        'network' => $network,
        'gross_amount' => $gross,
        'network_fee' => null,
        'amount_sent' => null,
        'mode' => 'instant',
        'status' => 'pending',
        'tx_hash' => null,
        'sent_at' => null,
    ], $attributes));

    return [$withdrawal, $owner];
}

function seedTokenValuations(string $tokenNetwork, string $nativeUsd, string $tokenUsd): void
{
    $nativeKey = $tokenNetwork === 'usdt_trc20' ? 'native_trx' : 'native_eth';
    UsdValuation::updateOrCreate(['network' => $nativeKey], ['conversion_value' => $nativeUsd]);
    UsdValuation::updateOrCreate(['network' => $tokenNetwork], ['conversion_value' => $tokenUsd]);
}

function createUnrecoveredSweep(User $owner, string $network, string $gasAmount): TreasurySweep
{
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => $network,
    ]);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $network,
        'gross_amount' => '10.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    $sweep = TreasurySweep::create([
        'deposit_id' => $deposit->id,
        'network' => $network,
        'amount' => '10.00000000',
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'fee_recovered_at' => null,
    ]);

    GasExpense::create([
        'expensable_type' => TreasurySweep::class,
        'expensable_id' => $sweep->id,
        'network' => $network,
        'tx_hash' => 'sweep-tx-'.$sweep->id,
        'amount' => $gasAmount,
    ]);

    return $sweep;
}

test('token withdrawal deducts buffered network fee converted to token units', function () {
    seedTokenValuations('usdt_trc20', '0.33', '1.00');
    [$withdrawal] = feeTestWithdrawal('usdt_trc20', '100.00000000');
    [$processor] = feeTestProcessor(fee: '5.00000000');

    $processor->process();

    $withdrawal->refresh();

    // charged_fee_native = 5 TRX * 1.2 = 6 TRX
    // fee_tokens = 6 * 0.33 / 1.00 = 1.98 USDT
    // amount_sent = 100 - 1.98 = 98.02 USDT
    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->network_fee)->toBe('1.98000000')
        ->and($withdrawal->amount_sent)->toBe('98.02000000');
});

test('sweep gas recovery is added to the token withdrawal fee and stamped as recovered', function () {
    seedTokenValuations('usdt_trc20', '0.33', '1.00');
    [$withdrawal, $owner] = feeTestWithdrawal('usdt_trc20', '100.00000000');
    $sweep = createUnrecoveredSweep($owner, 'usdt_trc20', '13.02850000');
    [$processor] = feeTestProcessor(fee: '5.00000000');

    $processor->process();

    $withdrawal->refresh();
    $sweep->refresh();

    // charged_fee_native = 5 * 1.2 = 6 TRX -> 1.98 USDT
    // recovery = 13.0285 * 0.33 / 1.00 = 4.29940500 USDT
    // total_fee = 6.27940500 USDT
    expect($withdrawal->network_fee)->toBe('6.27940500')
        ->and($withdrawal->amount_sent)->toBe('93.72059500')
        ->and($sweep->fee_recovered_at)->not->toBeNull()
        ->and($sweep->recovered_withdrawal_id)->toBe($withdrawal->id);

    // A second withdrawal for the same owner recovers nothing more.
    [$secondWithdrawal] = feeTestWithdrawal('usdt_trc20', '100.00000000', ['user_id' => $owner->id]);
    [$secondProcessor] = feeTestProcessor(fee: '5.00000000');
    $secondProcessor->process();
    $secondWithdrawal->refresh();

    expect($secondWithdrawal->network_fee)->toBe('1.98000000')
        ->and($secondWithdrawal->amount_sent)->toBe('98.02000000');
});

test('token withdrawal is not sent when native valuation is missing', function () {
    UsdValuation::updateOrCreate(['network' => 'usdt_trc20'], ['conversion_value' => '1.00']);
    [$withdrawal] = feeTestWithdrawal('usdt_trc20', '100.00000000');
    [$processor] = feeTestProcessor(fee: '5.00000000');

    $processor->process();

    $withdrawal->refresh();

    expect($withdrawal->status)->toBe('pending')
        ->and($withdrawal->network_fee)->toBeNull()
        ->and($withdrawal->amount_sent)->toBeNull()
        ->and(LedgerEntry::query()->where('withdrawal_id', $withdrawal->id)->count())->toBe(0);
});

test('bitcoin withdrawal deducts buffered native fee', function () {
    [$withdrawal] = feeTestWithdrawal('bitcoin', '1.00000000');
    [$processor] = feeTestProcessor(fee: '0.00020000');

    $processor->process();

    $withdrawal->refresh();

    // charged_fee = 0.0002 BTC * 1.2 = 0.00024 BTC
    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->network_fee)->toBe('0.00024000')
        ->and($withdrawal->amount_sent)->toBe('0.99976000');
});

test('network fee ledger entry is written with correct sign and links', function () {
    seedTokenValuations('usdt_trc20', '0.33', '1.00');
    [$withdrawal, $owner] = feeTestWithdrawal('usdt_trc20', '100.00000000');
    [$processor] = feeTestProcessor(fee: '5.00000000');

    $processor->process();

    $entry = LedgerEntry::query()
        ->where('withdrawal_id', $withdrawal->id)
        ->where('reason', 'network_fee')
        ->first();

    expect($entry)->not->toBeNull()
        ->and((string) $entry->amount)->toBe('-1.98000000')
        ->and($entry->user_id)->toBe($owner->id)
        ->and($entry->network)->toBe('usdt_trc20');
});

test('amount sent floors at zero when fees exceed gross', function () {
    seedTokenValuations('usdt_trc20', '0.33', '1.00');
    [$withdrawal] = feeTestWithdrawal('usdt_trc20', '1.00000000');
    [$processor] = feeTestProcessor(fee: '100.00000000');

    $processor->process();

    $withdrawal->refresh();

    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->amount_sent)->toBe('0.00000000');
});
