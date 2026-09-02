<?php

declare(strict_types=1);

use App\Livewire\Admin\TreasuryOverview;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasPolicy;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\GasTreasuryService;

class ProfitPreviewBroadcaster implements BlockchainBroadcaster
{
    public function __construct(public ?string $fee = '0.20000000') {}

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
        return $this->fee;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return null;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return 'payout-tx-123';
    }
}

function profitFixture(string $network = 'usdt_trc20', string $ownerBalance = '90.00000000', string $pendingWithdrawal = '20.00000000', string $paidOut = '5.00000000', string $unswept = '40.00000000', string $available = '100.00000000'): array
{
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    PlatformSettings::instance()->update([
        "profit_address_$network" => $network === 'bitcoin' ? '1BoatSLRHtKNngkdXEeobR76b53LETtpyT' : 'T111111111111111111111111111111111',
    ]);

    $wallet = TreasuryWallet::factory()->create([
        'network' => $network,
        'derivation_index' => 0,
        'address' => "treasury-$network",
        'available_funds' => $available,
        'native_balance' => $network === 'bitcoin' ? '0.50000000' : '50.00000000',
    ]);

    if ($network !== 'bitcoin') {
        GasPolicy::factory()->create(['network' => $network, 'reserve_threshold' => '1.00000000']);
    }

    if (bccomp($ownerBalance, '0', 8) > 0) {
        Balance::factory()->create([
            'user_id' => $owner->id,
            'network' => $network,
            'amount' => $ownerBalance,
        ]);
    }

    if (bccomp($pendingWithdrawal, '0', 8) > 0) {
        Withdrawal::factory()->create([
            'user_id' => $owner->id,
            'network' => $network,
            'gross_amount' => $pendingWithdrawal,
            'status' => 'pending',
        ]);
    }

    if (bccomp($unswept, '0', 8) > 0) {
        $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => $network]);
        Deposit::factory()->create([
            'deposit_address_id' => $address->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'network' => $network,
            'gross_amount' => $unswept,
            'status' => 'credited',
            'credited_at' => now(),
            'swept_at' => null,
        ]);
    }

    if (bccomp($paidOut, '0', 8) > 0) {
        TreasuryPayout::create([
            'network' => $network,
            'destination_address' => 'T111111111111111111111111111111111',
            'amount' => $paidOut,
            'status' => 'confirmed',
            'created_by' => $admin->id,
        ]);
    }

    UsdValuation::create(['network' => $network, 'conversion_value' => '1.000000']);
    if ($network !== 'bitcoin') {
        UsdValuation::create([
            'network' => $network === 'usdt_trc20' ? 'native_trx' : 'native_eth',
            'conversion_value' => '0.300000',
        ]);
    }

    return [$admin, $wallet];
}

test('profit table renders per-network withdrawable total liabilities paid out and summary', function () {
    [$admin] = profitFixture();

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Profit')
        ->assertSee('30.00 USDT')
        ->assertSee('110.00 USDT')
        ->assertSee('5.00 USDT')
        ->assertSee('Withdrawable now:')
        ->assertSee('Total profit:')
        ->assertSee('$30.00 USD');
});

test('deficit fixture shows a deficit badge', function () {
    [$admin] = profitFixture(ownerBalance: '150.00000000');

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Deficit')
        ->assertSee('-30.00 USDT');
});

test('missing profit address shows set payout address link and hides withdraw button', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    TreasuryWallet::factory()->create([
        'network' => 'usdt_trc20',
        'available_funds' => '100.00000000',
    ]);
    GasPolicy::factory()->create(['network' => 'usdt_trc20', 'reserve_threshold' => '1.00000000']);
    UsdValuation::create(['network' => 'usdt_trc20', 'conversion_value' => '1.000000']);

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Set payout address')
        ->assertDontSee('Withdraw profit');
});

test('open payout pre-fills saved destination and full withdrawable amount', function () {
    [$admin] = profitFixture();

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->call('openPayout', 'usdt_trc20')
        ->assertSet('payoutNetwork', 'usdt_trc20')
        ->assertSet('payoutDestination', 'T111111111111111111111111111111111')
        ->assertSet('payoutAmount', '30')
        ->assertSet('payoutStep', 'form');
});

test('preview payout rejects an amount larger than withdrawable profit', function () {
    [$admin] = profitFixture();

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->call('openPayout', 'usdt_trc20')
        ->set('payoutAmount', '31')
        ->call('previewPayout')
        ->assertHasErrors(['payoutAmount']);
});

test('preview payout happy path reaches confirm with fee level', function () {
    [$admin] = profitFixture();
    app()->instance(BlockchainBroadcaster::class, new ProfitPreviewBroadcaster('0.20000000'));

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->call('openPayout', 'usdt_trc20')
        ->set('payoutAmount', '30')
        ->call('previewPayout')
        ->assertSet('payoutStep', 'confirm')
        ->assertSet('payoutPreview.level', 'ok');
});

test('blocked payout preview prevents confirm from creating a payout', function () {
    [$admin] = profitFixture();
    app()->instance(BlockchainBroadcaster::class, new ProfitPreviewBroadcaster('1.50000000'));

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->call('openPayout', 'usdt_trc20')
        ->set('payoutAmount', '8')
        ->call('previewPayout')
        ->assertSet('payoutStep', 'confirm')
        ->assertSet('payoutPreview.level', 'block')
        ->call('confirmPayout')
        ->assertSet('payoutStep', 'error');

    expect(TreasuryPayout::where('status', 'pending')->orWhere('amount', '8.00000000')->count())->toBe(0);
});

test('deep link opens the payout modal for a valid network', function () {
    [$admin] = profitFixture();

    Livewire::withQueryParams(['payout' => 'usdt_trc20'])
        ->actingAs($admin)
        ->test(TreasuryOverview::class)
        ->assertSet('payoutModal', true)
        ->assertSet('payoutNetwork', 'usdt_trc20');
});

test('deep link is ignored for an invalid network', function () {
    [$admin] = profitFixture();

    Livewire::withQueryParams(['payout' => 'nonsense'])
        ->actingAs($admin)
        ->test(TreasuryOverview::class)
        ->assertSet('payoutModal', false);
});

test('wallet balances section is removed and network snapshot shows operational summary', function () {
    [$admin] = profitFixture(network: 'bitcoin', unswept: '0.00000000', pendingWithdrawal: '0.00000000', ownerBalance: '0.00000000');

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertDontSee('Wallet balances')
        ->assertSee('Network snapshot')
        ->assertSee('treasury-bitcoin')
        ->assertSee('Not applicable')
        ->assertDontSee('Low gas')
        ->assertDontSee('Paused');
});

test('secondary controls render in a single tab group', function () {
    [$admin] = profitFixture();

    $this->actingAs($admin)
        ->get(route('admin.treasury'))
        ->assertOk()
        ->assertSee('Gas controls')
        ->assertSee('Sweeps')
        ->assertSee('Profit payouts')
        ->assertSee('Top-ups')
        ->assertSee('Gas expenses');
});

test('polling refresh does not reset payout form or modal state', function () {
    [$admin] = profitFixture();

    $service = Mockery::mock(GasTreasuryService::class);
    $service->shouldReceive('policy')->with('usdt_trc20')->andReturnUsing(
        fn () => GasPolicy::query()->where('network', 'usdt_trc20')->firstOrFail()
    );
    $service->shouldReceive('refreshStaleTreasuryWallets')->once();
    app()->instance(GasTreasuryService::class, $service);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->call('openPayout', 'usdt_trc20')
        ->set('payoutAmount', '12')
        ->call('refreshTreasuryData')
        ->assertSet('payoutModal', true)
        ->assertSet('payoutStep', 'form')
        ->assertSet('payoutAmount', '12');
});

test('stale wallet refresh attempts provider call and preserves last known balance on failure', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $wallet = TreasuryWallet::factory()->create([
        'network' => 'usdt_trc20',
        'native_balance' => '5.00000000',
        'refreshed_at' => now()->subMinutes(5),
    ]);
    GasPolicy::factory()->create(['network' => 'usdt_trc20', 'reserve_threshold' => '1.00000000']);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('getNativeBalance')->andReturn(null);
    $broadcaster->shouldReceive('getTronResource')->andReturn(null);
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($admin)
        ->test(TreasuryOverview::class)
        ->call('refreshTreasuryData');

    expect($wallet->refresh()->native_balance)->toBe('5.00000000');
});

test('owners are forbidden from the treasury page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.treasury'))
        ->assertForbidden();
});
