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

    /** @var array<int, int> */
    public array $withdrawalIds = [];

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return null;
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        $this->withdrawalIds[] = $withdrawal->id;

        return $this->hash;
    }
}

function withdrawalProcessor(?string $hash = 'withdrawal-tx-123'): array
{
    $broadcaster = new WithdrawalBroadcasterFake;
    $broadcaster->hash = $hash;

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
        ->and($withdrawal->amount_sent)->toBe('1.25000000');
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

test('it leaves a withdrawal ready for retry when broadcasting fails', function () {
    $withdrawal = withdrawal();
    [$processor] = withdrawalProcessor(null);

    $processor->process();

    expect($withdrawal->fresh()->status)->toBe('pending')
        ->and($withdrawal->fresh()->tx_hash)->toBeNull()
        ->and($withdrawal->fresh()->sent_at)->toBeNull();
});

test('it does not broadcast a sent withdrawal twice', function () {
    $withdrawal = withdrawal();
    [$processor, $broadcaster] = withdrawalProcessor();

    $processor->process();
    $processor->process();

    expect($withdrawal->fresh()->status)->toBe('sent')
        ->and($broadcaster->withdrawalIds)->toBe([$withdrawal->id]);
});
