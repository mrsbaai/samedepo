<?php

declare(strict_types=1);

use App\Models\EnergyRental;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\EnergyFloatLow;
use App\Notifications\LowGasAlert;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\EstimatesTransferFee;
use App\Services\Blockchain\GasTreasuryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class GasTreasuryBroadcasterFake implements BlockchainBroadcaster, EstimatesTransferFee
{
    public ?string $transferFee = null;

    public array $transferFeeCalls = [];

    public array $topupCalls = [];

    public ?string $topupHash = 'topup-tx-123';

    public ?string $fee = '0.00100000';

    public ?string $tokenFee = null;

    public ?string $nativeTopupFee = null;

    public ?string $balance = '0.00000010';

    public ?string $recipientBalance = null;

    public ?string $treasuryBalance = null;

    public ?string $receiptStatus = 'confirmed';

    public ?string $receiptFee = '0.00010000';

    public ?int $receiptConfirmations = 12;

    public ?array $tronResource = null;

    public ?int $transferEnergy = null;

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
        if ($index === 0) {
            return $this->treasuryBalance ?? $this->balance;
        }

        return $this->recipientBalance ?? $this->balance;
    }

    public function getTokenBalance(string $network, int $index): ?string
    {
        return null;
    }

    public function getTronResource(int $index): ?array
    {
        return $this->tronResource ?? [
            'energy_limit' => 100000,
            'energy_used' => 0,
            'bandwidth_limit' => 100000,
            'bandwidth_used' => 0,
            'free_bandwidth_limit' => 600,
            'free_bandwidth_used' => 0,
        ];
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
        return $tokenTransfer ? ($this->tokenFee ?? $this->fee) : ($this->nativeTopupFee ?? $this->fee);
    }

    public function estimateTransferFee(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?string
    {
        $this->transferFeeCalls[] = compact('network', 'tokenTransfer', 'destination', 'sourceIndex');

        return $this->transferFee ?? $this->estimateFee($network, $tokenTransfer);
    }

    public function estimateTransferResources(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?array
    {
        $fee = $this->estimateTransferFee($network, $tokenTransfer, $destination, $sourceIndex);

        return $fee === null ? null : ['fee' => $fee, 'energy' => $this->transferEnergy ?? null];
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        $this->topupCalls[] = compact('network', 'sourceIndex', 'destinationIndex', 'amount', 'fee');

        return $this->topupHash;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }
}

class LegacyGasTreasuryBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $fee = '6.77350000';

    public ?string $recipientBalance = '0.00000000';

    public ?string $treasuryBalance = '30.00000000';

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
        return $index === 0 ? $this->treasuryBalance : $this->recipientBalance;
    }

    public function getTokenBalance(string $network, int $index): ?string
    {
        return null;
    }

    public function getTronResource(int $index): ?array
    {
        return null;
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        return ['status' => 'confirmed', 'fee' => '0.27000000', 'confirmations' => 20];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return $this->fee;
    }

    public function estimateTransferResources(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?array
    {
        return $this->fee === null ? null : ['fee' => $this->fee, 'energy' => null];
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return 'legacy-topup-tx';
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }
}

function gasTreasury(array $options = []): array
{
    $broadcaster = new GasTreasuryBroadcasterFake;
    $broadcaster->balance = $options['balance'] ?? '0.00000010';
    $broadcaster->recipientBalance = $options['recipientBalance'] ?? null;
    $broadcaster->treasuryBalance = $options['treasuryBalance'] ?? null;
    $broadcaster->receiptStatus = $options['receiptStatus'] ?? 'confirmed';
    $broadcaster->receiptConfirmations = $options['receiptConfirmations'] ?? 12;
    $broadcaster->tokenFee = $options['tokenFee'] ?? null;
    $broadcaster->nativeTopupFee = $options['nativeTopupFee'] ?? null;
    $broadcaster->transferFee = $options['transferFee'] ?? null;

    return [new GasTreasuryService($broadcaster), $broadcaster];
}

test('it creates or loads a gas policy for a network', function () {
    [$service] = gasTreasury();

    $policy = $service->policy('usdt_erc20');

    expect($policy)->toBeInstanceOf(GasPolicy::class);
    expect($policy->network)->toBe('native_eth');
    expect(GasPolicy::query()->count())->toBe(1);

    expect($service->policy('usdt_erc20')->id)->toBe($policy->id);
});

test('it requests a top-up when gas is below the reserve threshold', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service] = gasTreasury([
        'balance' => '0.00000010',
        'treasuryBalance' => '1000.00000000',
    ]);

    $ready = $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect($ready)->toBeTrue();

    $topup = GasTopup::query()->where('recipient_address', '0xabc')->first();
    expect($topup)->not->toBeNull();
    expect($topup->status)->toBe('confirmed');
    expect($topup->tx_hash)->toBe('topup-tx-123');
    expect((string) $topup->amount)->toBe('0.02000000');

    $expense = GasExpense::query()->where('gas_topup_id', $topup->id)->first();
    expect($expense)->not->toBeNull();
    expect($expense->amount)->toBe('0.00010000');
});

test('it does not create duplicate pending top-ups for the same address', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service] = gasTreasury([
        'balance' => '0.00000010',
        'receiptStatus' => 'pending',
        'treasuryBalance' => '1000.00000000',
    ]);

    $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');
    $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect(GasTopup::query()->where('recipient_address', '0xabc')->count())->toBe(1);
});

test('it reports gas ready without broadcasting when the balance is above reserve', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
    ]);

    [$service] = gasTreasury(['balance' => '1.00000000']);

    $ready = $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect($ready)->toBeTrue();
    expect(GasTopup::query()->count())->toBe(0);
});

test('it does not request a top-up when the network is manually paused', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'manual_paused' => true,
    ]);

    [$service] = gasTreasury();

    $ready = $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect($ready)->toBeFalse();
    expect(GasTopup::query()->count())->toBe(0);
});

test('it polls an unconfirmed top-up to confirmed', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
    ]);

    [$service, $broadcaster] = gasTreasury([
        'balance' => '0.00000010',
        'receiptStatus' => 'pending',
        'treasuryBalance' => '1000.00000000',
    ]);

    $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');
    $topup = GasTopup::query()->first();
    expect($topup->status)->toBe('broadcast');

    $broadcaster->receiptStatus = 'confirmed';
    $service->pollTopups();

    $topup->refresh();
    expect($topup->status)->toBe('confirmed');
});

test('it refreshes treasury native balance and tron resources', function () {
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);

    [$service] = gasTreasury(['balance' => '50.00000000']);
    $service->refreshTreasuryWallet($wallet);

    $wallet->refresh();
    expect($wallet->native_balance)->toBe('50.00000000');
    expect($wallet->energy)->toBe(100000);
    expect($wallet->bandwidth)->toBe(100000);
    expect($wallet->refreshed_at)->not->toBeNull();
});

test('it queues low gas email alerts only to active administrators', function () {
    Notification::fake();
    $activeAdmin = User::factory()->create(['role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    $inactiveAdmin = User::factory()->create(['role' => 'admin', 'is_admin' => true, 'is_active' => false]);
    $owner = User::factory()->create(['role' => 'owner', 'is_admin' => false, 'is_active' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'last_alert_at' => null,
        'alert_cooldown' => 60,
    ]);

    [$service] = gasTreasury(['balance' => '0.00010000']);
    $service->ensureGasForWithdrawal(Withdrawal::factory()->make(['network' => 'usdt_erc20']));

    Notification::assertSentTo($activeAdmin, LowGasAlert::class);
    Notification::assertNotSentTo($inactiveAdmin, LowGasAlert::class);
    Notification::assertNotSentTo($owner, LowGasAlert::class);
});

test('it provides network-specific policy defaults', function () {
    [$service] = gasTreasury();

    expect($service->policy('usdt_erc20')->reserve_threshold)->toBe('0.00500000')
        ->and($service->policy('usdt_erc20')->top_up_amount)->toBe('0.00030000')
        ->and($service->policy('usdt_erc20')->max_top_up)->toBe('0.00100000')
        ->and($service->policy('usdt_trc20')->reserve_threshold)->toBe('10.00000000')
        ->and($service->policy('usdt_trc20')->top_up_amount)->toBe('1.00000000')
        ->and($service->policy('usdt_trc20')->max_top_up)->toBe('20.00000000');
});

test('it sends an alert only after the cooldown expires', function () {
    User::factory()->create(['role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    $policy = GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'last_alert_at' => now()->subMinutes(30),
        'alert_cooldown' => 60,
    ]);

    [$service] = gasTreasury(['balance' => '0.00000010']);
    $service->ensureGasForWithdrawal(Withdrawal::factory()->make(['network' => 'usdt_erc20']));

    $policy->refresh();
    expect($policy->last_alert_at->diffInMinutes(now()))->toBeGreaterThanOrEqual(29);

    $policy->update(['last_alert_at' => now()->subMinutes(90)]);
    $service->ensureGasForWithdrawal(Withdrawal::factory()->make(['network' => 'usdt_erc20']));

    $policy->refresh();
    expect($policy->last_alert_at->diffInMinutes(now()))->toBeLessThan(1);
});

test('it polls an existing broadcast top-up to confirmed on a later scheduler run', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service, $broadcaster] = gasTreasury([
        'balance' => '0.00000010',
        'receiptStatus' => 'pending',
        'treasuryBalance' => '1000.00000000',
    ]);

    expect($service->ensureGasForSweep('usdt_erc20', 5, '0xabc'))->toBeFalse();
    $topup = GasTopup::query()->where('recipient_address', '0xabc')->first();
    expect($topup)->not->toBeNull();
    expect($topup->status)->toBe('broadcast');

    $broadcaster->receiptStatus = 'confirmed';
    expect($service->ensureGasForSweep('usdt_erc20', 5, '0xabc'))->toBeTrue();

    $topup->refresh();
    expect($topup->status)->toBe('confirmed');
    expect(GasExpense::query()->where('gas_topup_id', $topup->id)->count())->toBe(1);
});

test('it does not confirm a mined receipt until configured network confirmations are reached', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service, $broadcaster] = gasTreasury([
        'balance' => '0.00000010',
        'receiptStatus' => 'confirmed',
        'treasuryBalance' => '1000.00000000',
    ]);
    $broadcaster->receiptConfirmations = 1;

    $ready = $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect($ready)->toBeFalse();
    $topup = GasTopup::query()->where('recipient_address', '0xabc')->first();
    expect($topup->status)->toBe('broadcast');
    expect(GasExpense::query()->where('gas_topup_id', $topup->id)->count())->toBe(0);

    $broadcaster->receiptConfirmations = 12;
    expect($service->ensureGasForSweep('usdt_erc20', 5, '0xabc'))->toBeTrue();

    $topup->refresh();
    expect($topup->status)->toBe('confirmed');
    expect(GasExpense::query()->where('gas_topup_id', $topup->id)->count())->toBe(1);
});

test('it creates only one gas expense idempotently when a top-up is retried', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service, $broadcaster] = gasTreasury([
        'balance' => '0.00000010',
        'receiptStatus' => 'pending',
        'treasuryBalance' => '1000.00000000',
    ]);
    $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    $topup = GasTopup::query()->where('recipient_address', '0xabc')->first();
    $topup->update(['status' => 'confirmed', 'confirmed_at' => now(), 'is_open' => (string) $topup->id]);
    GasExpense::create([
        'gas_topup_id' => $topup->id,
        'network' => $topup->network,
        'tx_hash' => $topup->tx_hash,
        'amount' => '0.00010000',
        'expensable_type' => GasTopup::class,
        'expensable_id' => $topup->id,
    ]);

    $topup->update(['status' => 'broadcast', 'is_open' => 'open']);
    $broadcaster->receiptStatus = 'confirmed';
    $broadcaster->receiptConfirmations = 12;
    $service->pollTopups();

    $topup->refresh();
    expect($topup->status)->toBe('confirmed');
    expect(GasExpense::query()->where('gas_topup_id', $topup->id)->count())->toBe(1);
});

test('it prevents concurrent duplicate top-ups for the same address', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service] = gasTreasury([
        'balance' => '0.00000010',
        'receiptStatus' => 'pending',
        'treasuryBalance' => '1000.00000000',
    ]);

    $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');
    $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect(GasTopup::query()->where('recipient_address', '0xabc')->count())->toBe(1);
});

test('it returns ready when recipient balance covers the token sweep fee', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service] = gasTreasury([
        'balance' => '0.00500000',
        'tokenFee' => '0.00100000',
    ]);

    $ready = $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect($ready)->toBeTrue();
    expect(GasTopup::query()->count())->toBe(0);
});

test('it tops up at least the configured amount even when the token fee is lower', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service] = gasTreasury([
        'balance' => '0.00000010',
        'tokenFee' => '0.00050000',
        'nativeTopupFee' => '0.00010000',
        'treasuryBalance' => '1000.00000000',
    ]);

    $ready = $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect($ready)->toBeTrue();

    $topup = GasTopup::query()->where('recipient_address', '0xabc')->first();
    expect($topup)->not->toBeNull();
    expect((string) $topup->amount)->toBe('0.02000000');
});

test('it refuses to top up and alerts when treasury cannot retain the reserve threshold', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
        'last_alert_at' => null,
    ]);

    [$service] = gasTreasury([
        'balance' => '0.00000010',
        'tokenFee' => '0.00100000',
        'nativeTopupFee' => '0.00050000',
        'treasuryBalance' => '0.02500000',
    ]);

    $ready = $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect($ready)->toBeFalse();
    expect(GasTopup::query()->count())->toBe(0);
    Notification::assertSentTo($admin, LowGasAlert::class);
});

test('it leaves pending without broadcasting when required top-up exceeds max top up', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_eth',
        'reserve_threshold' => '0.01000000',
        'top_up_amount' => '0.02000000',
        'max_top_up' => '0.05000000',
    ]);

    [$service] = gasTreasury([
        'balance' => '0.00000010',
        'tokenFee' => '0.06000000',
        'treasuryBalance' => '1000.00000000',
    ]);

    $ready = $service->ensureGasForSweep('usdt_erc20', 5, '0xabc');

    expect($ready)->toBeFalse();
    expect(GasTopup::query()->where('recipient_address', '0xabc')->count())->toBe(0);
});

test('it does not compare recipient TRX balance to the treasury reserve threshold for tron', function () {
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '100.00000000',
        'top_up_amount' => '200.00000000',
        'max_top_up' => '1000.00000000',
    ]);

    [$service] = gasTreasury([
        'balance' => '50.00000000',
        'tokenFee' => '20.00000000',
        'nativeTopupFee' => '1.00000000',
        'treasuryBalance' => '1000.00000000',
    ]);

    $ready = $service->ensureGasForSweep('usdt_trc20', 5, 'Tabc');

    expect($ready)->toBeTrue();
    expect(GasTopup::query()->count())->toBe(0);
});

test('it sizes the top-up to the buffered fee minus the recipient balance', function () {
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20']);
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'top_up_amount' => '1.00000000',
        'max_top_up' => '20.00000000',
    ]);

    [$service, $broadcaster] = gasTreasury([
        'tokenFee' => '6.77350000',
        'nativeTopupFee' => '0.27000000',
        'recipientBalance' => '0.00000000',
        'treasuryBalance' => '30.00000000',
        'receiptConfirmations' => 20,
    ]);

    $ready = $service->ensureGasForSweep('usdt_trc20', 5, 'TRecipient');

    expect($ready)->toBeTrue();

    $topup = GasTopup::query()->sole();
    expect((string) $topup->amount)->toBe('8.12820000');
    expect($topup->kind)->toBe('topup');

    expect($broadcaster->transferFeeCalls[0])->toMatchArray([
        'tokenTransfer' => true,
        'destination' => (string) $wallet->address,
        'sourceIndex' => 5,
    ]);
    expect($broadcaster->transferFeeCalls[1])->toMatchArray([
        'tokenTransfer' => false,
        'destination' => 'TRecipient',
        'sourceIndex' => 0,
    ]);
});

test('it floors the top-up at the policy minimum', function () {
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20']);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'top_up_amount' => '1.00000000',
        'max_top_up' => '20.00000000',
    ]);

    [$service] = gasTreasury([
        'tokenFee' => '0.50000000',
        'nativeTopupFee' => '0.27000000',
        'recipientBalance' => '0.00000000',
        'treasuryBalance' => '30.00000000',
        'receiptConfirmations' => 20,
    ]);

    expect($service->ensureGasForSweep('usdt_trc20', 5, 'TRecipient'))->toBeTrue();
    expect((string) GasTopup::query()->sole()->amount)->toBe('1.00000000');
});

test('it creates no top-up above the policy cap', function () {
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20']);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'top_up_amount' => '1.00000000',
        'max_top_up' => '20.00000000',
    ]);

    [$service, $broadcaster] = gasTreasury([
        'tokenFee' => '18.00000000',
        'nativeTopupFee' => '0.27000000',
        'recipientBalance' => '0.00000000',
        'treasuryBalance' => '30.00000000',
    ]);

    expect($service->ensureGasForSweep('usdt_trc20', 5, 'TRecipient'))->toBeFalse();
    expect(GasTopup::query()->count())->toBe(0);
    expect($broadcaster->topupCalls)->toBeEmpty();
});

test('it skips the top-up when the recipient already holds enough', function () {
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20']);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'top_up_amount' => '1.00000000',
        'max_top_up' => '20.00000000',
    ]);

    [$service, $broadcaster] = gasTreasury([
        'tokenFee' => '6.77350000',
        'recipientBalance' => '7.00000000',
        'treasuryBalance' => '30.00000000',
    ]);

    expect($service->ensureGasForSweep('usdt_trc20', 5, 'TRecipient'))->toBeTrue();
    expect(GasTopup::query()->count())->toBe(0);
    expect($broadcaster->topupCalls)->toBeEmpty();
});

test('the reserve check uses the real native transfer fee', function () {
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20']);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'top_up_amount' => '1.00000000',
        'max_top_up' => '20.00000000',
    ]);

    [$service, $broadcaster] = gasTreasury([
        'tokenFee' => '6.77350000',
        'nativeTopupFee' => '0.27000000',
        'recipientBalance' => '0.00000000',
        'treasuryBalance' => '19.00000000',
        'receiptConfirmations' => 20,
    ]);

    // 19 - (8.1282 top-up + 0.27 native fee) = 10.6018 >= 10 reserve — under the
    // old flat 20-TRX estimate this same treasury would have been rejected.
    expect($service->ensureGasForSweep('usdt_trc20', 5, 'TRecipient'))->toBeTrue();
    expect(GasTopup::query()->count())->toBe(1);

    $broadcaster->treasuryBalance = '18.00000000';
    expect($service->ensureGasForSweep('usdt_trc20', 6, 'TRecipient2'))->toBeFalse();
    expect(GasTopup::query()->where('recipient_address', 'TRecipient2')->count())->toBe(0);
    expect(GasTopup::query()->count())->toBe(1);
});

test('it falls back to estimateFee when the broadcaster lacks EstimatesTransferFee', function () {
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20']);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'top_up_amount' => '1.00000000',
        'max_top_up' => '20.00000000',
    ]);

    $service = new GasTreasuryService(new LegacyGasTreasuryBroadcasterFake);

    expect($service->ensureGasForSweep('usdt_trc20', 5, 'TRecipient'))->toBeTrue();
    expect(GasTopup::query()->count())->toBe(1);
});

test('it provisions a sweep top-up while a recovery is in flight', function () {
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20']);
    $wallet = TreasuryWallet::factory()->create([
        'network' => 'usdt_trc20',
        'derivation_index' => 0,
        'address' => 'TTreasury',
    ]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'top_up_amount' => '1.00000000',
        'max_top_up' => '20.00000000',
    ]);
    GasTopup::create([
        'treasury_wallet_id' => $wallet->id,
        'network' => 'usdt_trc20',
        'kind' => 'recovery',
        'recipient_address' => 'TTreasury',
        'recipient_index' => 0,
        'amount' => '23.07000000',
        'tx_hash' => 'recovery-tx-1',
        'status' => 'broadcast',
        'broadcasted_at' => now(),
        'is_open' => 'open',
    ]);

    [$service] = gasTreasury([
        'tokenFee' => '6.77350000',
        'nativeTopupFee' => '0.27000000',
        'recipientBalance' => '0.00000000',
        'treasuryBalance' => '30.00000000',
        'receiptConfirmations' => 20,
    ]);

    expect($service->ensureGasForSweep('usdt_trc20', 5, 'TRecipient'))->toBeTrue();
    expect(GasTopup::query()->where('kind', 'topup')->where('recipient_address', 'TRecipient')->count())->toBe(1);
});

test('refreshTreasuryWallet stores the tronsave float in rent mode', function () {
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'energy_mode' => 'rent',
    ]);
    Http::fake([
        'https://api.tronsave.io/v2/user-info' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['id' => 'acc', 'balance' => '50000000', 'depositAddress' => 'TDep']]),
    ]);

    [$service] = gasTreasury(['balance' => '50.00000000']);
    $service->refreshTreasuryWallet($wallet);

    expect($wallet->refresh()->rental_balance)->toBe('50.00000000');
});

test('a low float sends the energy.float_low alert once per cooldown', function () {
    Notification::fake();
    Log::spy();
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'energy_mode' => 'rent',
        'rent_float_alert_trx' => '20.00000000',
        'alert_cooldown' => 60,
        'last_alert_at' => null,
    ]);
    Http::fake([
        'https://api.tronsave.io/v2/user-info' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['id' => 'acc', 'balance' => '5000000', 'depositAddress' => 'TDep']]),
    ]);

    [$service] = gasTreasury(['balance' => '50.00000000']);
    $service->refreshTreasuryWallet($wallet);

    Notification::assertSentTo($admin, EnergyFloatLow::class);
    Log::shouldHaveReceived('warning')
        ->with('energy.float_low', Mockery::on(fn ($context) => $context['network'] === 'native_trx'));

    $service->refreshTreasuryWallet($wallet);

    Notification::assertSentTimes(EnergyFloatLow::class, 1);
});

test('a bandwidth shortfall on the treasury receiver needs no top-up', function () {
    // Withdrawals/payouts pay the ~0.345 TRX bandwidth burn from the treasury's
    // own balance — the treasury never sends TRX to itself.
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'energy_mode' => 'rent',
    ]);
    [$service, $broadcaster] = gasTreasury(['treasuryBalance' => '30.00000000']);
    $broadcaster->tronResource = [
        'bandwidth_used' => 0, 'bandwidth_limit' => 0,
        'free_bandwidth_used' => 600, 'free_bandwidth_limit' => 600,
        'energy_used' => 0, 'energy_limit' => 80000,
    ];
    Http::fake();

    $withdrawal = Withdrawal::factory()->make(['network' => 'usdt_trc20', 'destination_address' => 'TDest']);

    expect($service->ensureGasForWithdrawal($withdrawal, '5.00000000'))->toBeTrue()
        ->and(GasTopup::count())->toBe(0)
        ->and($broadcaster->topupCalls)->toHaveCount(0)
        ->and(EnergyRental::count())->toBe(0);
});

test('burn mode never calls tronsave', function () {
    Http::fake();
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0]);
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'reserve_threshold' => '10.00000000',
        'energy_mode' => 'burn',
    ]);

    [$service] = gasTreasury(['balance' => '50.00000000']);
    $service->refreshTreasuryWallet($wallet);

    expect($wallet->refresh()->rental_balance)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.tronsave.io'));
});
