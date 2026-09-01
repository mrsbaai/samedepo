<?php

declare(strict_types=1);

use App\Models\GasExpense;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\TreasuryPayoutService;

class PayoutBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $hash = 'payout-tx-123';

    public string $receiptStatus = 'confirmed';

    public ?string $fee = '0.00100000';

    public function broadcastSweep(\App\Models\TreasurySweep $sweep): ?string
    {
        return null;
    }

    public function broadcastWithdrawal(\App\Models\Withdrawal $withdrawal): ?string
    {
        return null;
    }

    public function estimateWithdrawalFee(\App\Models\Withdrawal $withdrawal): ?string
    {
        return null;
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
        return [
            'status' => $this->receiptStatus,
            'fee' => '0.00010000',
            'confirmations' => 3,
        ];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return $this->fee;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return null;
    }

    public function broadcastPayout(\App\Models\TreasuryPayout $payout): ?string
    {
        return $this->hash;
    }
}

test('treasury payout is broadcast and decrements available funds', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $wallet = TreasuryWallet::factory()->create([
        'network' => 'bitcoin',
        'derivation_index' => 0,
        'address' => 'treasury-btc',
        'available_funds' => '5.00000000',
    ]);

    $payout = TreasuryPayout::create([
        'network' => 'bitcoin',
        'destination_address' => '1A...',
        'amount' => '1.50000000',
        'status' => 'pending',
        'created_by' => $admin->id,
    ]);

    $service = new TreasuryPayoutService(new PayoutBroadcasterFake);

    expect($service->send($payout))->toBeTrue();

    $payout->refresh();
    expect($payout->status)->toBe('sent')
        ->and($payout->tx_hash)->toBe('payout-tx-123')
        ->and($payout->sent_at)->not->toBeNull();

    $wallet->refresh();
    expect($wallet->available_funds)->toBe('3.49900000');
});

test('treasury payout is rejected when amount exceeds available funds', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $wallet = TreasuryWallet::factory()->create([
        'network' => 'bitcoin',
        'derivation_index' => 0,
        'address' => 'treasury-btc',
        'available_funds' => '1.00000000',
    ]);

    $payout = TreasuryPayout::create([
        'network' => 'bitcoin',
        'destination_address' => '1A...',
        'amount' => '1.50000000',
        'status' => 'pending',
        'created_by' => $admin->id,
    ]);

    $service = new TreasuryPayoutService(new PayoutBroadcasterFake);

    expect($service->send($payout))->toBeFalse();

    $payout->refresh();
    expect($payout->status)->toBe('failed');

    $wallet->refresh();
    expect($wallet->available_funds)->toBe('1.00000000');
});

test('treasury payout poll confirms and records a gas expense', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $payout = TreasuryPayout::create([
        'network' => 'bitcoin',
        'destination_address' => '1A...',
        'amount' => '1.00000000',
        'status' => 'sent',
        'tx_hash' => 'payout-tx-123',
        'sent_at' => now(),
        'created_by' => $admin->id,
    ]);

    $service = new TreasuryPayoutService(new PayoutBroadcasterFake);
    $service->poll();

    $payout->refresh();
    expect($payout->status)->toBe('confirmed')
        ->and($payout->confirmed_at)->not->toBeNull();

    $expense = GasExpense::query()->where('expensable_type', TreasuryPayout::class)->where('expensable_id', $payout->id)->first();
    expect($expense)->not->toBeNull()
        ->and($expense->amount)->toBe('0.00010000');
});

test('treasury payout poll marks a failed receipt as failed', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $payout = TreasuryPayout::create([
        'network' => 'bitcoin',
        'destination_address' => '1A...',
        'amount' => '1.00000000',
        'status' => 'sent',
        'tx_hash' => 'payout-tx-123',
        'sent_at' => now(),
        'created_by' => $admin->id,
    ]);

    $broadcaster = new PayoutBroadcasterFake;
    $broadcaster->receiptStatus = 'failed';
    $service = new TreasuryPayoutService($broadcaster);
    $service->poll();

    $payout->refresh();
    expect($payout->status)->toBe('failed')
        ->and($payout->error_message)->not->toBeNull();
});
