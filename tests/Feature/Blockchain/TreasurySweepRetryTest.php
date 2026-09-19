<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\TreasurySweepService;

class SweepRetryBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $hash = 'sweep-tx';

    public string $receiptStatus = 'pending';

    public ?string $tokenBalance = null;

    public ?string $nativeBalance = '1000.00000000';

    public ?string $recipientBalance = '1000.00000000';

    public int $broadcasts = 0;

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        $this->broadcasts++;

        return $this->hash;
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
        return $index === 0 ? $this->nativeBalance : $this->recipientBalance;
    }

    public function getTokenBalance(string $network, int $index): ?string
    {
        return $this->tokenBalance;
    }

    public function getTronResource(int $index): ?array
    {
        return ['energy_limit' => 100000, 'energy_used' => 0, 'bandwidth_limit' => 100000, 'bandwidth_used' => 0];
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        return ['status' => $txHash === 'topup-tx' ? 'pending' : $this->receiptStatus, 'fee' => '0.00100000', 'confirmations' => 3];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return '0.00100000';
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return 'topup-tx';
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }
}

function trc20Group(string $amount = '13.50000000'): DepositAddress
{
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'usdt_trc20',
        'derivation_index' => 5,
        'address' => 'Tdeposit',
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => $amount,
        'status' => 'credited',
        'credited_at' => now(),
        'swept_at' => null,
    ]);

    return $address;
}

beforeEach(function () {
    PlatformSettings::instance();
    PlatformSettings::networkSetting('usdt_trc20')->update(['sweep_min_usd' => '0.00']);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => '1.000000']);
    TreasuryWallet::factory()->create(['network' => 'usdt_trc20', 'derivation_index' => 0, 'available_funds' => '0.00000000']);
});

test('a failed sweep seeds backoff on its replacement instead of rebroadcasting', function () {
    trc20Group();
    $broadcaster = new SweepRetryBroadcasterFake;
    $broadcaster->tokenBalance = '1000000.00000000';
    $broadcaster->receiptStatus = 'failed';
    $service = new TreasurySweepService($broadcaster);

    $service->sweep();

    expect($broadcaster->broadcasts)->toBe(1)
        ->and(TreasurySweep::sole()->status)->toBe('failed')
        ->and(TreasurySweep::sole()->attempts)->toBe(1);

    $service->sweep();

    $replacement = TreasurySweep::where('status', 'pending')->sole();
    expect($broadcaster->broadcasts)->toBe(1)
        ->and($replacement->attempts)->toBe(1)
        ->and($replacement->last_attempted_at)->not->toBeNull();

    $this->travel(1)->minutes();
    $service->sweep();
    expect($broadcaster->broadcasts)->toBe(1);

    $this->travel(2)->minutes();
    $service->sweep();
    expect($broadcaster->broadcasts)->toBe(2)
        ->and($replacement->fresh()->status)->toBe('failed');
});

test('a sweep for tokens the address does not hold fails before gas top-up', function () {
    trc20Group();
    $broadcaster = new SweepRetryBroadcasterFake;
    $broadcaster->tokenBalance = '0.00000000';
    $broadcaster->recipientBalance = '0.00000000';
    $service = new TreasurySweepService($broadcaster);

    $service->sweep();

    $sweep = TreasurySweep::sole();
    expect($broadcaster->broadcasts)->toBe(0)
        ->and($sweep->status)->toBe('pending')
        ->and($sweep->attempts)->toBe(1)
        ->and($sweep->error_message)->toStartWith('on_chain_balance_short')
        ->and(GasTopup::count())->toBe(0);
});

test('a null token balance skips the sweep without broadcasting or provisioning gas', function () {
    trc20Group();
    $broadcaster = new SweepRetryBroadcasterFake;
    $broadcaster->recipientBalance = '0.00000000';
    $service = new TreasurySweepService($broadcaster);

    $service->sweep();

    $sweep = TreasurySweep::sole();
    expect($broadcaster->broadcasts)->toBe(0)
        ->and($sweep->status)->toBe('pending')
        ->and($sweep->attempts)->toBe(0)
        ->and(GasTopup::count())->toBe(0);

    $broadcaster->tokenBalance = '1000000.00000000';
    $broadcaster->recipientBalance = '1000.00000000';
    $service->sweep();

    expect($broadcaster->broadcasts)->toBe(1);
});
