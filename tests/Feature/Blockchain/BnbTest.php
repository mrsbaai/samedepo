<?php

use App\Models\BlockchainScanState;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\GasPolicy;
use App\Models\GasTopup;
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
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\Providers\EtherscanNativeProvider;
use App\Services\Blockchain\TreasurySweepService;
use App\Services\Blockchain\WithdrawalProcessor;
use App\Support\Network;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class BnbTestBroadcaster implements BlockchainBroadcaster
{
    public ?string $sweepHash = 'sweep-tx-123';

    public ?string $withdrawalHash = 'withdrawal-tx-123';

    public ?string $topupHash = 'topup-tx-123';

    public ?string $fee = '0.00010000';

    public ?string $recipientBalance = '10.00000000';

    public ?string $treasuryBalance = '1000.00000000';

    public ?string $tokenBalance = '1000000.00000000';

    public string $topupReceiptStatus = 'pending';

    /** @var array<int, string> */
    public array $sweptNetworks = [];

    /** @var array<int, array{amount: string, fee: string}> */
    public array $topupCalls = [];

    /** @var array<int, array{network: string, tokenTransfer: bool}> */
    public array $estimateCalls = [];

    public function broadcastSweep(TreasurySweep $sweep): ?string
    {
        $this->sweptNetworks[] = $sweep->network;

        return $this->sweepHash !== null ? $this->sweepHash.'-'.$sweep->network : null;
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
        return $index === 0 ? $this->treasuryBalance : $this->recipientBalance;
    }

    public function getTokenBalance(string $network, int $index): ?string
    {
        return $this->tokenBalance;
    }

    public function getTronResource(int $index): ?array
    {
        return null;
    }

    public function getTransactionReceipt(string $network, string $txHash): ?array
    {
        if ($txHash === $this->topupHash) {
            return ['status' => $this->topupReceiptStatus, 'fee' => '0.00001000', 'confirmations' => 15];
        }

        return ['status' => 'confirmed', 'fee' => '0.00010000', 'confirmations' => 15];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        $this->estimateCalls[] = ['network' => $network, 'tokenTransfer' => $tokenTransfer];

        return $this->fee;
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        $this->topupCalls[] = ['amount' => $amount, 'fee' => $fee];

        return $this->topupHash;
    }
}

function bnbOwner(): array
{
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    PlatformSettings::instance();

    return [$owner, $customer];
}

function bnbEtherscanTx(array $overrides = []): array
{
    return array_merge([
        'blockNumber' => '1000',
        'hash' => '0xbnb-tx-1',
        'from' => '0x'.str_pad('77', 40, '7', STR_PAD_LEFT),
        'to' => '0x'.str_pad('1', 40, '0', STR_PAD_LEFT),
        'value' => '500000000000000000',
        'confirmations' => '15',
        'isError' => '0',
        'txreceipt_status' => '1',
    ], $overrides);
}

function bnbProvider(array $txs, array &$requests = [], ?callable $sleeper = null): EtherscanNativeProvider
{
    Http::fake(function (Request $request) use (&$requests, $txs) {
        $requests[] = $request->data();

        return Http::response(['status' => '1', 'message' => 'OK', 'result' => $txs]);
    });

    return new EtherscanNativeProvider(
        network: 'bnb',
        apiKey: 'test-key',
        chainId: 56,
        baseUrl: 'https://etherscan.test/api',
        sleeper: $sleeper,
    );
}

test('bnb is a disabled native bsc network on the shared evm group', function () {
    expect(Network::isNative('bnb'))->toBeTrue()
        ->and(Network::isEvm('bnb'))->toBeTrue()
        ->and(Network::chain('bnb'))->toBe('bsc')
        ->and(Network::nativeKey('bnb'))->toBe('native_bnb')
        ->and(Network::addressGroup('bnb'))->toBe('evm')
        ->and(Network::confirmations('bnb'))->toBe(15)
        ->and(Network::explorerTx('bnb'))->toBe('https://bscscan.com/tx/{hash}')
        ->and(Network::enabledKeys())->not->toContain('bnb');
});

test('address generator shares the evm derivation for bnb', function () {
    config([
        'blockchain.usdt_erc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
    ]);

    $generator = app(AddressGenerator::class);

    expect($generator->generate('bnb', 3))
        ->toBe($generator->generate('usdt_bep20', 3))
        ->toBe($generator->generate('ethereum', 3));
});

test('sync-networks provisions bnb sharing the evm wallet without a gas policy', function () {
    config([
        'networks.networks.bnb.enabled' => true,
        'blockchain.bitcoin.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
        'blockchain.usdt_trc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
        'blockchain.usdt_erc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
    ]);

    Artisan::call('app:sync-networks');

    $bnb = TreasuryWallet::where('network', 'bnb')->first();
    $evm = TreasuryWallet::where('network', 'usdt_erc20')->first();

    expect($bnb)->not->toBeNull()
        ->and($bnb->derivation_index)->toBe($evm->derivation_index)
        ->and($bnb->address)->toBe($evm->address)
        // Native asset: no token gas policy row for bnb; native_bnb policy belongs to the bep20 tokens.
        ->and(GasPolicy::where('network', 'bnb')->exists())->toBeFalse();
});

test('bnb scan detects a deposit pending then credits at 15 confirmations via chainid 56', function () {
    [$owner, $customer] = bnbOwner();
    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'bnb',
        'address' => $address,
        'derivation_index' => 3,
    ]);
    config(['networks.networks.bnb.scan_interval' => 0]);

    $requests = [];
    $confirmations = '8';
    Http::fake(function (Request $request) use (&$requests, &$confirmations, $address) {
        $requests[] = $request->data();

        return Http::response(['status' => '1', 'message' => 'OK', 'result' => [
            bnbEtherscanTx(['to' => $address, 'confirmations' => $confirmations]),
        ]]);
    });

    $provider = fn () => new EtherscanNativeProvider('bnb', 'test-key', 56, 'https://etherscan.test/api');

    (new DepositScanner([$provider()]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit = Deposit::where('tx_hash', '0xbnb-tx-1')->first();
    expect($requests[0]['chainid'])->toBe(56)
        ->and($deposit)->not->toBeNull()
        ->and($deposit->network)->toBe('bnb')
        ->and($deposit->status)->toBe('pending')
        ->and($deposit->confirmation_count)->toBe(8);

    $confirmations = '15';
    (new DepositScanner([$provider()]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited')
        ->and($deposit->confirmation_count)->toBe(15)
        ->and(BlockchainScanState::where('network', 'bnb')->value('last_scanned_block'))->not->toBeNull();
});

test('the etherscan throttle is shared across provider instances', function () {
    // The shared timestamp is static — reset it so earlier tests in the
    // process don't leak a "recent request" into this test.
    (new ReflectionProperty(EtherscanNativeProvider::class, 'lastRequestAt'))->setValue(null, 0.0);

    $sleeps = [];
    $sleeper = function (int $us) use (&$sleeps): void {
        $sleeps[] = $us;
    };
    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);

    Http::fake(fn () => Http::response(['status' => '1', 'message' => 'OK', 'result' => []]));

    $ethereum = new EtherscanNativeProvider('ethereum', 'test-key', 1, 'https://etherscan.test/api', $sleeper);
    $bnb = new EtherscanNativeProvider('bnb', 'test-key', 56, 'https://etherscan.test/api', $sleeper);

    $ethereum->fetchTransactions([$address]);
    $bnb->fetchTransactions([$address]);

    // The bnb instance's first request must wait on the ethereum instance's
    // timestamp: exactly one sleep, sized to whatever is left of the 200ms
    // interval after real elapsed time between the calls.
    expect($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBeGreaterThan(0)
        ->and($sleeps[0])->toBeLessThanOrEqual(200_000);
});

test('bnb sweep takes the fee out of the amount on the native branch', function () {
    [$owner, $customer] = bnbOwner();
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bnb']);
    $wallet = TreasuryWallet::factory()->create(['network' => 'bnb', 'available_funds' => 0]);

    PlatformSettings::networkSetting('bnb')->update(['sweep_min_usd' => '0.00']);
    UsdValuation::factory()->create(['network' => 'bnb', 'conversion_value' => '1.000000']);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bnb',
        'gross_amount' => '0.50000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    $gas = Mockery::mock(GasTreasuryService::class);
    $gas->shouldNotReceive('ensureGasForSweep', 'ensureGasForWithdrawal');

    (new TreasurySweepService(new BnbTestBroadcaster, $gas))->sweep();

    $sweep = TreasurySweep::where('deposit_address_id', $address->id)->first();
    expect($sweep)->not->toBeNull()
        ->and($sweep->tx_hash)->toBe('sweep-tx-123-bnb')
        ->and($sweep->network)->toBe('bnb')
        ->and($wallet->fresh()->available_funds)->toBe('0.49990000');
});

test('bnb withdrawal sends gross minus fee and spends sent plus fee', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    TreasuryWallet::firstOrCreate(
        ['network' => 'bnb'],
        ['derivation_index' => 0, 'address' => 'treasury-bnb', 'available_funds' => '10.00000000'],
    );

    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bnb',
        'gross_amount' => '1.25000000',
        'network_fee' => null,
        'amount_sent' => null,
        'mode' => 'instant',
        'status' => 'pending',
    ]);

    $broadcaster = new BnbTestBroadcaster;
    $broadcaster->fee = '0.25000000';

    (new WithdrawalProcessor($broadcaster))->process();

    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->amount_sent)->toBe('0.95000000')
        ->and(TreasuryWallet::where('network', 'bnb')->first()->available_funds)->toBe('8.75000000');
});

test('bnb broadcaster requests are marked as native not token transfers', function () {
    Http::fake(fn () => Http::response(['data' => ['fee' => '0.00010000', 'tx_hash' => '0xok']]));

    $broadcaster = new RemoteBlockchainBroadcaster('https://signer.test', 'key');
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bnb',
        'destination_address' => '0x'.str_repeat('a', 40),
    ]);

    $broadcaster->estimateWithdrawalFee($withdrawal);

    Http::assertSent(fn (Request $request) => $request->data()['token_transfer'] === false
        && $request->data()['network'] === 'bnb');
});

test('bnb valuations use the binancecoin feed', function () {
    expect(Network::coingeckoId('bnb'))->toBe('binancecoin')
        ->and(Network::valuationKeys())->toContain('bnb');
});

test('stranded-gas recovery skips an address holding a credited unswept sibling deposit', function () {
    config(['networks.networks.usdt_bep20.enabled' => true]);
    [$owner, $customer] = bnbOwner();
    $token = DepositAddress::factory()->create([
        'customer_id' => $customer->id, 'network' => 'usdt_bep20',
        'address' => '0xabc123', 'derivation_index' => 7,
    ]);
    // Sibling row on the same shared 0x address carrying a credited unswept native BNB deposit.
    $native = DepositAddress::factory()->create([
        'customer_id' => $customer->id, 'network' => 'bnb',
        'address' => '0xabc123', 'derivation_index' => 7,
    ]);
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_bep20', 'derivation_index' => 0]);

    GasTopup::create([
        'network' => 'usdt_bep20',
        'kind' => 'topup',
        'recipient_address' => '0xabc123',
        'recipient_index' => 7,
        'treasury_wallet_id' => $wallet->id,
        'amount' => '0.00020000',
        'status' => 'confirmed',
        'is_open' => '1',
    ]);

    Deposit::factory()->create([
        'deposit_address_id' => $native->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'bnb',
        'gross_amount' => '0.02000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    $broadcaster = new BnbTestBroadcaster;
    $broadcaster->tokenBalance = '0.00000000';
    $broadcaster->recipientBalance = '0.00500000';
    $broadcaster->topupReceiptStatus = 'confirmed';

    (new GasTreasuryService($broadcaster))->recoverStrandedGas();

    expect($broadcaster->topupCalls)->toHaveCount(0)
        ->and(GasTopup::where('network', 'usdt_bep20')->where('kind', 'recovery')->exists())->toBeFalse();

    // Once the BNB deposit is swept the stranded residue is recoverable again.
    Deposit::query()->update(['swept_at' => now()]);

    (new GasTreasuryService($broadcaster))->recoverStrandedGas();

    expect($broadcaster->topupCalls)->toHaveCount(1);
});
