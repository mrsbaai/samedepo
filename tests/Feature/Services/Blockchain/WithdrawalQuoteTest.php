<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\LedgerEntry;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\WithdrawalProcessor;
use App\Services\Blockchain\WithdrawalQuote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WithdrawalQuoteBroadcasterFake implements BlockchainBroadcaster
{
    public ?string $fee = '0.00100000';

    public ?string $withdrawalFee = '5.00000000';

    public ?int $transferEnergy = 65000;

    public ?string $transferFee = '0.00100000';

    public ?string $nativeTransferFee = '0.00050000';

    public int $estimateFeeCalls = 0;

    public int $withdrawalFeeCalls = 0;

    public int $transferResourceCalls = 0;

    public array $tronResources = [];

    public ?array $tronResource = ['activated' => true];

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        return 'sweep-tx-123';
    }

    public function broadcastWithdrawal(Withdrawal $withdrawal): ?string
    {
        return 'withdrawal-tx-123';
    }

    public function estimateWithdrawalFee(Withdrawal $withdrawal): ?string
    {
        $this->withdrawalFeeCalls++;

        return $this->withdrawalFee;
    }

    public function getNativeBalance(string $network, int $index): ?string
    {
        return '1000.00000000';
    }

    public function getTokenBalance(string $network, int $index): ?string
    {
        return null;
    }

    public function getTronResource(int $index): ?array
    {
        return $this->tronResources[$index] ?? $this->tronResource;
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        return ['status' => 'confirmed', 'fee' => '0.00010000', 'confirmations' => 3];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        $this->estimateFeeCalls++;

        return $this->fee;
    }

    public function estimateTransferResources(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?array
    {
        $this->transferResourceCalls++;

        return [
            'fee' => $tokenTransfer ? $this->transferFee : $this->nativeTransferFee,
            'energy' => $tokenTransfer ? $this->transferEnergy : null,
        ];
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        return 'topup-tx-123';
    }
}

function quoteValuations(string $nativeKey, string $tokenNetwork, string $nativeUsd = '0.33', string $tokenUsd = '1.00'): void
{
    UsdValuation::updateOrCreate(['network' => $nativeKey], ['conversion_value' => $nativeUsd]);
    UsdValuation::updateOrCreate(['network' => $tokenNetwork], ['conversion_value' => $tokenUsd]);
}

function quoteWallet(string $network, string $availableFunds = '1000.00000000'): TreasuryWallet
{
    return TreasuryWallet::firstOrCreate(
        ['network' => $network],
        [
            'derivation_index' => 0,
            'address' => 'treasury-'.$network,
            'available_funds' => $availableFunds,
            'native_balance' => '1000.00000000',
        ],
    );
}

function quoteUnsweptDeposit(User $owner, string $network, int $index): void
{
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => $network,
        'derivation_index' => $index,
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $network,
        'gross_amount' => '10.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);
}

function quoteConfirmedSweep(User $owner, string $network, string $topupAmount, int $index = 3): void
{
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => $network,
        'derivation_index' => $index,
    ]);
    GasTopup::create([
        'treasury_wallet_id' => TreasuryWallet::where('network', $network)->value('id'),
        'network' => $network,
        'recipient_address' => $address->address,
        'recipient_index' => $index,
        'amount' => $topupAmount,
        'tx_hash' => 'topup-'.$address->id,
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'is_open' => 'done',
    ]);
    $deposit = Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $network,
        'gross_amount' => '10.00000000',
        'status' => 'credited',
        'credited_at' => now(),
        'swept_at' => now(),
    ]);
    TreasurySweep::create([
        'deposit_id' => $deposit->id,
        'network' => $network,
        'amount' => '10.00000000',
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ]);
}

beforeEach(function () {
    Cache::flush();
});

test('a token network quotes the burn method with a buffered fee', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20');
    $owner = User::factory()->create(['role' => 'owner']);

    $quote = (new WithdrawalQuote(new WithdrawalQuoteBroadcasterFake))
        ->quote($owner->id, 'usdt_trc20', '100.00000000');

    // 0.001 TRX buffered to 0.0012 -> 0.0012 * 0.33 / 1.00 = 0.000396 USDT.
    expect($quote['network_fee']['method'])->toBe('burn')
        ->and($quote['network_fee']['estimate_native'])->toBe('0.00100000')
        ->and($quote['network_fee']['buffered_native'])->toBe('0.00120000')
        ->and($quote['network_fee']['amount'])->toBe('0.00039600')
        ->and($quote['network_fee']['native_usd'])->toBe('0.330000')
        ->and($quote['network_fee']['token_usd'])->toBe('1.000000')
        ->and($quote['consolidation_pending'])->toBeNull()
        ->and($quote['total_fee'])->toBe('0.00039600')
        ->and($quote['receive'])->toBe('99.99960400');
});

test('tron rent policy quotes the rental method from the tronsave estimate', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20');
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'energy_mode' => 'rent',
        'rent_max_price_sun' => 90,
        'rent_duration_sec' => 3600,
        'rent_energy_headroom_percent' => 10,
    ]);
    // simulated 65000 + 10% headroom = 71500 energy
    Http::fake([
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 4576000, 'availableResource' => 100000]]),
    ]);
    $owner = User::factory()->create(['role' => 'owner']);

    $quote = (new WithdrawalQuote(new WithdrawalQuoteBroadcasterFake))
        ->quote($owner->id, 'usdt_trc20', '100.00000000');

    // 4.576 TRX rental buffered to 5.4912 -> 5.4912 * 0.33 = 1.812096 USDT.
    expect($quote['network_fee']['method'])->toBe('rental')
        ->and($quote['network_fee']['estimate_native'])->toBe('4.57600000')
        ->and($quote['network_fee']['buffered_native'])->toBe('5.49120000')
        ->and($quote['network_fee']['amount'])->toBe('1.81209600')
        ->and($quote['receive'])->toBe('98.18790400');
});

test('a utxo native network quotes the miner method', function () {
    quoteValuations('bitcoin', 'bitcoin', '67000.00', '67000.00');
    quoteWallet('bitcoin');
    $owner = User::factory()->create(['role' => 'owner']);
    $broadcaster = new WithdrawalQuoteBroadcasterFake;
    $broadcaster->fee = '0.00005000';

    $quote = (new WithdrawalQuote($broadcaster))
        ->quote($owner->id, 'bitcoin', '0.50000000');

    expect($quote['network_fee']['method'])->toBe('miner')
        ->and($quote['network_fee']['buffered_native'])->toBe('0.00006000')
        ->and($quote['network_fee']['amount'])->toBe('0.00006000')
        ->and($quote['receive'])->toBe('0.49994000');
});

test('outstanding sweep costs surface with native amount and counts', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20');
    $owner = User::factory()->create(['role' => 'owner']);
    // 9.09090910 TRX * 0.33 = 3.00 USDT.
    quoteConfirmedSweep($owner, 'usdt_trc20', '9.09090910');

    $quote = (new WithdrawalQuote(new WithdrawalQuoteBroadcasterFake))
        ->quote($owner->id, 'usdt_trc20', '100.00000000');

    expect($quote['consolidation_outstanding']['amount'])->toBe('3.00000000')
        ->and($quote['consolidation_outstanding']['native'])->toBe('9.09090910')
        ->and($quote['consolidation_outstanding']['items']['sweeps'])->toBe(1)
        ->and($quote['consolidation_outstanding']['items']['topups'])->toBe(1)
        ->and($quote['consolidation_outstanding']['items']['rentals'])->toBe(0)
        ->and($quote['total_fee'])->toBe('3.00039600')
        ->and($quote['receive'])->toBe('96.99960400');
});

test('pending consolidation is null while the treasury can cover the withdrawal', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20', '1000.00000000');
    $owner = User::factory()->create(['role' => 'owner']);
    quoteUnsweptDeposit($owner, 'usdt_trc20', 7);

    $quote = (new WithdrawalQuote(new WithdrawalQuoteBroadcasterFake))
        ->quote($owner->id, 'usdt_trc20', '100.00000000');

    expect($quote['consolidation_pending'])->toBeNull();
});

test('a pending sweep on an unactivated tron address prices rental plus activation', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20', '5.00000000'); // treasury cannot cover 100 gross
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'energy_mode' => 'rent',
        'rent_max_price_sun' => 90,
        'rent_duration_sec' => 3600,
        'rent_energy_headroom_percent' => 10,
    ]);
    Http::fake([
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 4576000, 'availableResource' => 100000]]),
    ]);
    $owner = User::factory()->create(['role' => 'owner']);
    quoteUnsweptDeposit($owner, 'usdt_trc20', 7);

    $broadcaster = new WithdrawalQuoteBroadcasterFake;
    $broadcaster->tronResources = [7 => ['activated' => false]];

    $quote = (new WithdrawalQuote($broadcaster))
        ->quote($owner->id, 'usdt_trc20', '100.00000000');

    // Rental 4.576 TRX + 1.1 TRX activation = 5.676 TRX -> 5.676 * 0.33 = 1.87308 USDT.
    // total = 1.812096 network fee + 1.87308 pending = 3.685176.
    expect($quote['consolidation_pending']['addresses'])->toBe(1)
        ->and($quote['consolidation_pending']['native'])->toBe('5.67600000')
        ->and($quote['consolidation_pending']['amount'])->toBe('1.87308000')
        ->and($quote['receive'])->toBe('96.31482400');
});

test('a pending sweep on an activated tron address prices only the rental', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20', '5.00000000');
    GasPolicy::factory()->create([
        'network' => 'native_trx',
        'energy_mode' => 'rent',
        'rent_max_price_sun' => 90,
        'rent_duration_sec' => 3600,
        'rent_energy_headroom_percent' => 10,
    ]);
    Http::fake([
        'https://api.tronsave.io/v2/estimate-buy-resource' => Http::response(['error' => false, 'message' => 'Success', 'data' => ['unitPrice' => 64, 'durationSec' => 3600, 'estimateTrx' => 4576000, 'availableResource' => 100000]]),
    ]);
    $owner = User::factory()->create(['role' => 'owner']);
    quoteUnsweptDeposit($owner, 'usdt_trc20', 7);

    $quote = (new WithdrawalQuote(new WithdrawalQuoteBroadcasterFake))
        ->quote($owner->id, 'usdt_trc20', '100.00000000');

    // 4.576 TRX * 0.33 = 1.51008 USDT.
    expect($quote['consolidation_pending']['addresses'])->toBe(1)
        ->and($quote['consolidation_pending']['native'])->toBe('4.57600000')
        ->and($quote['consolidation_pending']['amount'])->toBe('1.51008000');
});

test('a pending sweep on an evm token prices the simulated burn fee', function () {
    quoteValuations('native_eth', 'usdt_erc20', '3000.00', '1.00');
    quoteWallet('usdt_erc20', '5.00000000');
    $owner = User::factory()->create(['role' => 'owner']);
    quoteUnsweptDeposit($owner, 'usdt_erc20', 4);

    $broadcaster = new WithdrawalQuoteBroadcasterFake;
    $broadcaster->transferFee = '0.00200000';
    $broadcaster->transferEnergy = null;

    $quote = (new WithdrawalQuote($broadcaster))
        ->quote($owner->id, 'usdt_erc20', '50.00000000');

    // Sweep burn 0.002 ETH + treasury top-up tx 0.0005 ETH = 0.0025 -> *3000 = 7.5 USDT.
    expect($quote['consolidation_pending']['addresses'])->toBe(1)
        ->and($quote['consolidation_pending']['native'])->toBe('0.00250000')
        ->and($quote['consolidation_pending']['amount'])->toBe('7.50000000');
});

test('the quote is null when the valuation is missing', function () {
    quoteWallet('usdt_trc20');
    $owner = User::factory()->create(['role' => 'owner']);
    $service = new WithdrawalQuote(new WithdrawalQuoteBroadcasterFake);

    expect($service->quote($owner->id, 'usdt_trc20', '100.00000000'))->toBeNull()
        ->and($service->lastFailure())->toBe('fee_conversion_failed');
});

test('the quote is null when the fee estimate is unavailable', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20');
    $owner = User::factory()->create(['role' => 'owner']);
    $broadcaster = new WithdrawalQuoteBroadcasterFake;
    $broadcaster->fee = null;
    $service = new WithdrawalQuote($broadcaster);

    expect($service->quote($owner->id, 'usdt_trc20', '100.00000000'))->toBeNull()
        ->and($service->lastFailure())->toBe('fee_unavailable');
});

test('a destination uses the destination-aware estimate, not the generic one', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20');
    $owner = User::factory()->create(['role' => 'owner']);
    $broadcaster = new WithdrawalQuoteBroadcasterFake;

    (new WithdrawalQuote($broadcaster))
        ->quote($owner->id, 'usdt_trc20', '100.00000000', destination: 'Tdest123');

    expect($broadcaster->estimateFeeCalls)->toBe(0)
        ->and($broadcaster->transferResourceCalls)->toBe(1);
});

test('fresh quotes bypass the estimate cache', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20');
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '100.00000000',
    ]);
    $service = new WithdrawalQuote($broadcaster = new WithdrawalQuoteBroadcasterFake);

    $service->quote($owner->id, 'usdt_trc20', '100.00000000', $withdrawal);
    expect($broadcaster->withdrawalFeeCalls)->toBe(1);

    // Warm cache: a second non-fresh quote does not hit the signer again.
    $service->quote($owner->id, 'usdt_trc20', '100.00000000', $withdrawal);
    expect($broadcaster->withdrawalFeeCalls)->toBe(1);

    $quote = $service->quote($owner->id, 'usdt_trc20', '100.00000000', $withdrawal, fresh: true);
    expect($broadcaster->withdrawalFeeCalls)->toBe(2)
        ->and($quote['network_fee']['estimate_native'])->toBe('5.00000000');
});

test('send locks exactly the quote values', function () {
    quoteValuations('native_trx', 'usdt_trc20');
    quoteWallet('usdt_trc20');
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '100.00000000',
        'mode' => 'instant',
        'status' => 'pending',
    ]);
    // 9.09090910 TRX * 0.33 = 3.00 USDT outstanding.
    quoteConfirmedSweep($owner, 'usdt_trc20', '9.09090910');

    $broadcaster = new WithdrawalQuoteBroadcasterFake;
    $expected = (new WithdrawalQuote($broadcaster))
        ->quote($owner->id, 'usdt_trc20', '100.00000000', $withdrawal);

    (new WithdrawalProcessor($broadcaster))->process();

    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->network_fee)->toBe($expected['network_fee']['amount'])
        ->and($withdrawal->network_fee_native)->toBe($expected['network_fee']['buffered_native'])
        ->and($withdrawal->consolidation_fee)->toBe($expected['consolidation_outstanding']['amount'])
        ->and($withdrawal->amount_sent)->toBe('95.02000000')
        ->and(LedgerEntry::query()->where('reason', 'consolidation_fee')->count())->toBe(1);
});
