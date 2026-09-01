<?php

declare(strict_types=1);

use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\WithdrawalProcessor;

class WithdrawalFundsGatingBroadcasterFake implements BlockchainBroadcaster
{
    public int $estimates = 0;

    public int $broadcasts = 0;

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return null;
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        $this->broadcasts++;

        return 'withdrawal-tx';
    }

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        $this->estimates++;

        return '0.10000000';
    }

    public function getNativeBalance(string $network, int $index): ?string
    {
        return '1000.00000000';
    }

    public function getTronResource(int $index): ?array
    {
        return null;
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
}

function gatedWithdrawal(string $funds): array
{
    $owner = User::factory()->create(['role' => 'owner']);
    $wallet = TreasuryWallet::factory()->create(['network' => 'bitcoin', 'available_funds' => $funds]);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => '10.00000000',
        'network_fee' => null,
        'amount_sent' => null,
        'mode' => 'instant',
        'status' => 'pending',
        'tx_hash' => null,
        'sent_at' => null,
    ]);

    return [$withdrawal, $wallet];
}

test('withdrawal is untouched when treasury funds do not cover gross amount', function () {
    [$withdrawal, $wallet] = gatedWithdrawal('9.99999999');
    $broadcaster = new WithdrawalFundsGatingBroadcasterFake;

    (new WithdrawalProcessor($broadcaster))->process();

    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('pending')
        ->and($withdrawal->network_fee)->toBeNull()
        ->and($withdrawal->amount_sent)->toBeNull()
        ->and($withdrawal->tx_hash)->toBeNull()
        ->and($wallet->fresh()->available_funds)->toBe('9.99999999')
        ->and($broadcaster->estimates)->toBe(0)
        ->and($broadcaster->broadcasts)->toBe(0);
});

test('sent withdrawal decrements treasury funds by amount sent', function () {
    [$withdrawal, $wallet] = gatedWithdrawal('15.00000000');
    $broadcaster = new WithdrawalFundsGatingBroadcasterFake;

    (new WithdrawalProcessor($broadcaster))->process();

    expect($withdrawal->fresh()->status)->toBe('sent')
        ->and($withdrawal->fresh()->amount_sent)->toBe('9.88000000')
        ->and($wallet->fresh()->available_funds)->toBe('5.12000000')
        ->and($broadcaster->broadcasts)->toBe(1);
});
