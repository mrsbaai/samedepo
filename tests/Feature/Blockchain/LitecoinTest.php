<?php

use App\Livewire\Dashboard\WithdrawalSettings;
use App\Models\Balance;
use App\Models\BlockchainScanState;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasPolicy;
use App\Models\NetworkSetting;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\AddressGenerator;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\RemoteBlockchainBroadcaster;
use App\Services\Blockchain\DepositCreditor;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\FeeConverter;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\Providers\EsploraProvider;
use App\Services\Blockchain\TreasurySweepService;
use App\Services\Blockchain\WithdrawalProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// Public BIP39 test mnemonic (abandon×11 + about) → LTC BIP84 account 0.
// Same vector pinned by the signer's test_networks_ma02.py.
const LTC_TEST_ZPUB = 'zpub6rPo5mF47z5coVm5rvWv7fv181awb7Vckn5Cf3xQXBVKu18kuBHDhNi1Jrb4br6vVD3ZbrnXemEsWJoR18mZwkUdzwD8TQnHDUCGxqZ6swA';

class LtcTestBroadcaster implements BlockchainBroadcaster
{
    public ?string $sweepHash = 'sweep-tx-123';

    public ?string $withdrawalHash = 'withdrawal-tx-123';

    public ?string $fee = '0.25000000';

    /** @var array<int, string> */
    public array $withdrawalAmounts = [];

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return $this->sweepHash;
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        return $this->withdrawalHash;
    }

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        return $this->fee;
    }

    public function getNativeBalance(string $network, int $index): ?string
    {
        return '10.00000000';
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
        return [
            'status' => 'confirmed',
            'fee' => '0.00010000',
            'confirmations' => 6,
        ];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return $this->fee;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return null;
    }
}

test('litecoin address derivation matches the signer for indices 0-3', function () {
    config(['blockchain.litecoin.xpub' => LTC_TEST_ZPUB]);

    $generator = app(AddressGenerator::class);

    expect($generator->generate('litecoin', 0))->toBe('ltc1qjmxnz78nmc8nq77wuxh25n2es7rzm5c2rkk4wh')
        ->and($generator->generate('litecoin', 1))->toBe('ltc1qwlezpr3890hcp6vva9twqh27mr6edadreqvhnn')
        ->and($generator->generate('litecoin', 2))->toBe('ltc1qc6aucuznvhh9uvux246x24vf9y9ncfk729m92s')
        ->and($generator->generate('litecoin', 3))->toBe('ltc1qr4uckk3jjxtknw5mtqmtwvt87955rc7ays0hsh');
});

test('esplora litecoin scan detects, pends, and credits at 6 confirmations', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'litecoin',
        'address' => 'ltc1qjmxnz78nmc8nq77wuxh25n2es7rzm5c2rkk4wh',
    ]);

    config(['networks.networks.litecoin.scan_interval' => 0]);
    PlatformSettings::instance();
    PlatformSettings::networkSetting('litecoin')->update(['min_deposit' => '0.00000000']);

    $tip = '1002';
    $tx = [
        'txid' => 'ltc-tx-1',
        'vout' => [['scriptpubkey_address' => 'ltc1qjmxnz78nmc8nq77wuxh25n2es7rzm5c2rkk4wh', 'value' => 100_000_000]],
        'status' => ['confirmed' => true, 'block_height' => 1000],
    ];

    Http::fake(function (Request $request) use (&$tip, $tx) {
        return str_contains($request->url(), 'tip/height')
            ? Http::response($tip)
            : Http::response([$tx]);
    });

    // 3 confirmations: pending only.
    (new DepositScanner([new EsploraProvider('litecoin', 'https://litecoinspace.org/api')]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit = Deposit::where('tx_hash', 'ltc-tx-1')->first();
    expect($deposit)->not->toBeNull()
        ->and($deposit->network)->toBe('litecoin')
        ->and($deposit->status)->toBe('pending')
        ->and($deposit->confirmation_count)->toBe(3);

    // 6 confirmations: credited.
    $tip = '1005';
    (new DepositScanner([new EsploraProvider('litecoin', 'https://litecoinspace.org/api')]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited')
        ->and($deposit->confirmation_count)->toBe(6)
        ->and(Balance::where('user_id', $owner->id)->where('network', 'litecoin')->exists())->toBeTrue();
});

test('litecoin sweep takes the fee out of the amount and never touches gas paths', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'litecoin']);
    $wallet = TreasuryWallet::factory()->create(['network' => 'litecoin', 'available_funds' => 0]);

    PlatformSettings::instance();
    PlatformSettings::networkSetting('litecoin')->update(['sweep_min_usd' => '0.00']);
    UsdValuation::factory()->create(['network' => 'litecoin', 'conversion_value' => '1.000000']);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'litecoin',
        'gross_amount' => '1.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    $gas = Mockery::mock(GasTreasuryService::class);
    $gas->shouldNotReceive('ensureGasForSweep', 'ensureGasForWithdrawal');
    app()->instance(GasTreasuryService::class, $gas);

    $sweeper = new TreasurySweepService(new LtcTestBroadcaster, $gas);
    $sweeper->sweep();

    $sweep = TreasurySweep::where('deposit_address_id', $address->id)->first();
    expect($sweep)->not->toBeNull()
        ->and($sweep->status)->toBe('confirmed')
        ->and($sweep->tx_hash)->toBe('sweep-tx-123')
        ->and($sweep->network)->toBe('litecoin')
        // Native branch: wallet is credited amount minus the reconciled receipt fee.
        ->and($wallet->fresh()->available_funds)->toBe('0.99990000');
});

test('litecoin withdrawal sends gross minus fee and spends sent plus fee', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    TreasuryWallet::firstOrCreate(
        ['network' => 'litecoin'],
        ['derivation_index' => 0, 'address' => 'treasury-litecoin', 'available_funds' => '1000.00000000'],
    );

    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'litecoin',
        'gross_amount' => '1.25000000',
        'network_fee' => null,
        'amount_sent' => null,
        'mode' => 'instant',
        'status' => 'pending',
    ]);

    $processor = new WithdrawalProcessor(new LtcTestBroadcaster);
    $processor->process();

    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->amount_sent)->toBe('0.95000000')
        // Native: treasury spends amount_sent + network_fee_native.
        ->and(TreasuryWallet::where('network', 'litecoin')->first()->available_funds)
        ->toBe('998.75000000');
});

test('fee converter returns litecoin amounts unchanged', function () {
    expect(app(FeeConverter::class)->toNetworkUnits('litecoin', '0.00012345'))->toBe('0.00012345');
});

test('sendFeeLimit does not alter litecoin fees', function () {
    $broadcaster = new RemoteBlockchainBroadcaster('http://x', 'k');
    $method = new ReflectionMethod($broadcaster, 'sendFeeLimit');
    expect($method->invoke($broadcaster, 'litecoin', '0.00010000'))->toBe('0.00010000');
});

test('withdrawal address validation accepts ltc1 and L addresses and rejects bc1 for litecoin', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    config(['networks.networks.litecoin.enabled' => true]);

    Livewire::actingAs($owner)
        ->test(WithdrawalSettings::class)
        ->call('startEdit', 'litecoin', '')
        ->set('editingAddress', 'ltc1qjmxnz78nmc8nq77wuxh25n2es7rzm5c2rkk4wh')
        ->call('confirmSave')
        ->assertHasNoErrors();

    Livewire::actingAs($owner)
        ->test(WithdrawalSettings::class)
        ->call('startEdit', 'litecoin', '')
        ->set('editingAddress', 'LQ3B9qFJNvDpCqyLJhMnDrXYy4hP4wRzE7')
        ->call('confirmSave')
        ->assertHasNoErrors();

    Livewire::actingAs($owner)
        ->test(WithdrawalSettings::class)
        ->call('startEdit', 'litecoin', '')
        ->set('editingAddress', 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh')
        ->call('confirmSave')
        ->assertHasErrors(['editingAddress' => 'regex']);
});

test('sync-networks creates litecoin rows but no gas policy', function () {
    config([
        'networks.networks.litecoin.enabled' => true,
        'blockchain.litecoin.xpub' => LTC_TEST_ZPUB,
    ]);

    Artisan::call('app:sync-networks');

    $wallet = TreasuryWallet::where('network', 'litecoin')->first();
    expect($wallet)->not->toBeNull()
        ->and($wallet->derivation_index)->toBe(0)
        ->and($wallet->address)->toBe('ltc1qjmxnz78nmc8nq77wuxh25n2es7rzm5c2rkk4wh');

    expect(NetworkSetting::where('network', 'litecoin')->exists())->toBeTrue()
        ->and(BlockchainScanState::where('network', 'litecoin')->exists())->toBeTrue();

    expect(GasPolicy::whereIn('network', ['litecoin', 'native_ltc', 'native_litecoin'])->count())->toBe(0);
});
