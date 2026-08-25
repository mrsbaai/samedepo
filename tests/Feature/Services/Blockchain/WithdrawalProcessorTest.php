<?php

declare(strict_types=1);

use App\Models\TreasurySweep;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\WithdrawalProcessor;

class WithdrawalBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $hash = 'withdrawal-tx-123';

    public ?string $fee = '0.25000000';

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
        ->and($withdrawal->network_fee)->toBe('0.25000000')
        ->and($withdrawal->amount_sent)->toBe('1.00000000');
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

test('it estimates fees for every supported network', function (string $network, string $gross, string $fee, string $sent) {
    $withdrawal = withdrawal(['network' => $network, 'gross_amount' => $gross]);
    [$processor] = withdrawalProcessor(fee: $fee);

    $processor->process();

    expect($withdrawal->fresh()->network_fee)->toBe($fee)
        ->and($withdrawal->fresh()->amount_sent)->toBe($sent);
})->with([
    ['bitcoin', '1.00000000', '0.00020000', '0.99980000'],
    ['usdt_trc20', '10.00000000', '1.00000000', '9.00000000'],
    ['usdt_erc20', '4.00000000', '5.00000000', '0.00000000'],
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
        ->and($withdrawal->fresh()->network_fee)->toBe('0.25000000')
        ->and($withdrawal->fresh()->amount_sent)->toBe('1.00000000')
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
