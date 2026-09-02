<?php

declare(strict_types=1);

use App\Models\GasPolicy;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\WithdrawalProcessor;

class WithdrawalBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $hash = 'withdrawal-tx-123';

    public ?string $fee = '0.25000000';

    public ?string $balance = '1000.00000000';

    /** @var array<int, int> */
    public array $withdrawalIds = [];

    /** @var array<int, int> */
    public array $estimatedWithdrawalIds = [];

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return null;
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        $this->withdrawalIds[] = $withdrawal->id;

        return $this->hash;
    }

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        $this->estimatedWithdrawalIds[] = $withdrawal->id;

        return $this->fee;
    }

    public function getNativeBalance(string $network, int $index): ?string
    {
        return $this->balance;
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
        return null;
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return '0.00100000';
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return null;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }
}

function withdrawalProcessor(?string $hash = 'withdrawal-tx-123', ?string $fee = '0.25000000'): array
{
    $broadcaster = new WithdrawalBroadcasterFake;
    $broadcaster->hash = $hash;
    $broadcaster->fee = $fee;

    return [new WithdrawalProcessor($broadcaster), $broadcaster];
}

function withdrawal(array $attributes = []): Withdrawal
{
    $owner = User::factory()->create(['role' => 'owner']);
    $network = $attributes['network'] ?? 'bitcoin';

    TreasuryWallet::firstOrCreate(
        ['network' => $network],
        ['derivation_index' => 0, 'address' => 'treasury-'.$network, 'available_funds' => '1000.00000000'],
    );

    return Withdrawal::factory()->create(array_merge([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '1.25000000',
        'network_fee' => null,
        'amount_sent' => null,
        'mode' => 'instant',
        'status' => 'pending',
        'tx_hash' => null,
        'sent_at' => null,
    ], $attributes));
}

test('it sends pending instant withdrawals', function () {
    $withdrawal = withdrawal();
    [$processor] = withdrawalProcessor();

    $processor->process();

    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->tx_hash)->toBe('withdrawal-tx-123')
        ->and($withdrawal->sent_at)->not->toBeNull()
        ->and($withdrawal->network_fee)->toBe('0.30000000')
        ->and($withdrawal->amount_sent)->toBe('0.95000000');
});

test('it leaves pending approval withdrawals reserved until approved', function () {
    $withdrawal = withdrawal(['mode' => 'approval']);
    [$processor, $broadcaster] = withdrawalProcessor();

    $processor->process();

    expect($withdrawal->fresh()->status)->toBe('pending')
        ->and($broadcaster->withdrawalIds)->toBe([]);
});

test('it sends approved withdrawals', function () {
    $withdrawal = withdrawal(['mode' => 'approval', 'status' => 'approved']);
    [$processor] = withdrawalProcessor();

    $processor->process();

    expect($withdrawal->fresh()->status)->toBe('sent')
        ->and($withdrawal->fresh()->tx_hash)->toBe('withdrawal-tx-123');
});

test('it does not send denied or cancelled withdrawals', function (string $status) {
    withdrawal(['status' => $status]);
    [$processor, $broadcaster] = withdrawalProcessor();

    $processor->process();

    expect($broadcaster->withdrawalIds)->toBe([]);
})->with(['denied', 'cancelled']);

test('it estimates fees for every supported network', function (string $network, string $gross, string $estimate, string $nativeUsd, string $expectedFee, string $sent) {
    $withdrawal = withdrawal(['network' => $network, 'gross_amount' => $gross]);
    [$processor] = withdrawalProcessor(fee: $estimate);

    if (in_array($network, ['usdt_trc20', 'usdt_erc20'], true)) {
        $nativeKey = $network === 'usdt_trc20' ? 'native_trx' : 'native_eth';
        UsdValuation::updateOrCreate(['network' => $nativeKey], ['conversion_value' => $nativeUsd]);
        UsdValuation::updateOrCreate(['network' => $network], ['conversion_value' => '1.000000']);
    }

    $processor->process();

    expect($withdrawal->fresh()->network_fee)->toBe($expectedFee)
        ->and($withdrawal->fresh()->amount_sent)->toBe($sent);
})->with([
    // Bitcoin uses native units directly; charged fee is estimate * 1.2.
    ['bitcoin', '1.00000000', '0.00020000', '0', '0.00024000', '0.99976000'],
    // Token networks convert via USD valuations. Native USD is the native coin price.
    ['usdt_trc20', '10.00000000', '1.00000000', '0.330000', '0.39600000', '9.60400000'],
    ['usdt_erc20', '100.00000000', '0.00100000', '2000.000000', '2.40000000', '97.60000000'],
]);

test('it leaves a withdrawal retryable when fee estimation fails', function () {
    $withdrawal = withdrawal();
    [$processor, $broadcaster] = withdrawalProcessor(fee: null);

    $processor->process();

    expect($withdrawal->fresh()->status)->toBe('pending')
        ->and($withdrawal->fresh()->network_fee)->toBeNull()
        ->and($withdrawal->fresh()->amount_sent)->toBeNull()
        ->and($broadcaster->withdrawalIds)->toBe([]);
});

test('it leaves a withdrawal ready for retry when broadcasting fails', function () {
    $withdrawal = withdrawal();
    [$processor] = withdrawalProcessor(null);

    $processor->process();

    expect($withdrawal->fresh()->status)->toBe('pending')
        ->and($withdrawal->fresh()->network_fee)->toBe('0.30000000')
        ->and($withdrawal->fresh()->amount_sent)->toBe('0.95000000')
        ->and($withdrawal->fresh()->tx_hash)->toBeNull()
        ->and($withdrawal->fresh()->sent_at)->toBeNull();
});

test('it does not broadcast a sent withdrawal twice', function () {
    $withdrawal = withdrawal();
    [$processor, $broadcaster] = withdrawalProcessor();

    $processor->process();
    $processor->process();

    expect($withdrawal->fresh()->status)->toBe('sent')
        ->and($broadcaster->estimatedWithdrawalIds)->toBe([$withdrawal->id])
        ->and($broadcaster->withdrawalIds)->toBe([$withdrawal->id]);
});

test('it leaves a token withdrawal pending when treasury gas is low', function () {
    $withdrawal = withdrawal(['network' => 'usdt_erc20', 'gross_amount' => '5.00000000']);
    [$processor, $broadcaster] = withdrawalProcessor();
    $broadcaster->balance = '0.00000100';

    GasPolicy::factory()->create([
        'network' => 'usdt_erc20',
        'reserve_threshold' => '0.01000000',
    ]);

    $processor->process();

    expect($withdrawal->fresh()->status)->toBe('pending')
        ->and($withdrawal->fresh()->tx_hash)->toBeNull()
        ->and($broadcaster->withdrawalIds)->toBe([]);
});
