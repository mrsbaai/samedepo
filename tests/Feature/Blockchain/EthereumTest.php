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
use App\Providers\AppServiceProvider;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\DepositCreditor;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\Providers\EtherscanNativeProvider;
use App\Services\Blockchain\Providers\EvmLogsProvider;
use App\Services\Blockchain\TreasurySweepService;
use App\Services\Blockchain\WithdrawalProcessor;
use App\Support\Network;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class EthTestBroadcaster implements BlockchainBroadcaster
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

function ethOwner(): array
{
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    PlatformSettings::instance();

    return [$owner, $customer];
}

function etherscanTx(array $overrides = []): array
{
    return array_merge([
        'blockNumber' => '1000',
        'hash' => '0xeth-tx-1',
        'from' => '0x'.str_pad('77', 40, '7', STR_PAD_LEFT),
        'to' => '0x'.str_pad('1', 40, '0', STR_PAD_LEFT),
        'value' => '1000000000000000000',
        'confirmations' => '12',
        'isError' => '0',
        'txreceipt_status' => '1',
    ], $overrides);
}

function etherscanProvider(array $txs, array &$requests = [], ?callable $sleeper = null): EtherscanNativeProvider
{
    Http::fake(function (Request $request) use (&$requests, $txs) {
        $requests[] = $request->data();

        return Http::response(['status' => '1', 'message' => 'OK', 'result' => $txs]);
    });

    return new EtherscanNativeProvider(
        network: 'ethereum',
        apiKey: 'test-key',
        chainId: 1,
        baseUrl: 'https://etherscan.test/api',
        sleeper: $sleeper,
    );
}

test('native eth scan detects a deposit pending then credits at 12 confirmations', function () {
    [$owner, $customer] = ethOwner();
    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'ethereum',
        'address' => $address,
        'derivation_index' => 3,
    ]);
    config(['networks.networks.ethereum.scan_interval' => 0]);

    $requests = [];
    $confirmations = '5';
    Http::fake(function (Request $request) use (&$requests, &$confirmations, $address) {
        $requests[] = $request->data();

        return Http::response(['status' => '1', 'message' => 'OK', 'result' => [
            etherscanTx(['to' => $address, 'confirmations' => $confirmations]),
        ]]);
    });

    $provider = fn () => new EtherscanNativeProvider('ethereum', 'test-key', 1, 'https://etherscan.test/api');

    (new DepositScanner([$provider()]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit = Deposit::where('tx_hash', '0xeth-tx-1')->first();
    expect($deposit)->not->toBeNull()
        ->and($deposit->network)->toBe('ethereum')
        ->and($deposit->status)->toBe('pending')
        ->and($deposit->confirmation_count)->toBe(5);

    $confirmations = '12';
    (new DepositScanner([$provider()]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited')
        ->and($deposit->confirmation_count)->toBe(12)
        ->and(BlockchainScanState::where('network', 'ethereum')->value('last_scanned_block'))->not->toBeNull();
});

test('treasury and gas top-up transfers to a shared address are never credited as eth deposits', function () {
    [$owner, $customer] = ethOwner();
    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);
    DepositAddress::factory()->create([
        'customer_id' => $customer->id,
        'network' => 'ethereum',
        'address' => $address,
        'derivation_index' => 3,
    ]);
    $wallet = TreasuryWallet::factory()->create([
        'network' => 'usdt_erc20',
        'derivation_index' => 0,
        'address' => '0x'.str_pad('aa', 40, 'a', STR_PAD_LEFT),
    ]);
    GasTopup::create([
        'network' => 'usdt_erc20',
        'kind' => 'topup',
        'recipient_address' => $address,
        'recipient_index' => 3,
        'treasury_wallet_id' => $wallet->id,
        'amount' => '0.00020000',
        'tx_hash' => '0xtopup-hash',
        'status' => 'confirmed',
        'is_open' => '1',
    ]);
    config(['networks.networks.ethereum.scan_interval' => 0]);

    $requests = [];
    $provider = etherscanProvider([
        // Our own gas top-up: from == treasury address.
        etherscanTx(['hash' => '0xfrom-treasury', 'from' => $wallet->address, 'to' => $address]),
        // A top-up by hash (e.g. sent before the wallet row existed).
        etherscanTx(['hash' => '0xtopup-hash', 'to' => $address]),
        // A tx going to the treasury address, not the customer address.
        etherscanTx(['hash' => '0xto-treasury', 'to' => $wallet->address]),
        // A failed/reverted tx.
        etherscanTx(['hash' => '0xreverted', 'to' => $address, 'txreceipt_status' => '0']),
        // A zero-value tx.
        etherscanTx(['hash' => '0xzero', 'to' => $address, 'value' => '0']),
        // The real customer deposit.
        etherscanTx(['hash' => '0xreal-deposit', 'to' => $address]),
    ], $requests);

    (new DepositScanner([$provider]))->scan();

    expect(Deposit::count())->toBe(1);
    expect(Deposit::first()->tx_hash)->toBe('0xreal-deposit');
});

test('eth sweep takes the fee out of the amount on the native branch', function () {
    [$owner, $customer] = ethOwner();
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'ethereum']);
    $wallet = TreasuryWallet::factory()->create(['network' => 'ethereum', 'available_funds' => 0]);

    PlatformSettings::networkSetting('ethereum')->update(['sweep_min_usd' => '0.00']);
    UsdValuation::factory()->create(['network' => 'ethereum', 'conversion_value' => '1.000000']);

    Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => 'ethereum',
        'gross_amount' => '0.50000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    $gas = Mockery::mock(GasTreasuryService::class);
    $gas->shouldNotReceive('ensureGasForSweep', 'ensureGasForWithdrawal');

    (new TreasurySweepService(new EthTestBroadcaster, $gas))->sweep();

    $sweep = TreasurySweep::where('deposit_address_id', $address->id)->first();
    expect($sweep)->not->toBeNull()
        ->and($sweep->tx_hash)->toBe('sweep-tx-123-ethereum')
        ->and($sweep->network)->toBe('ethereum')
        // Native branch: wallet credited amount − reconciled receipt fee.
        ->and($wallet->fresh()->available_funds)->toBe('0.49990000');
});

test('eth withdrawal sends gross minus fee and spends sent plus fee', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    TreasuryWallet::firstOrCreate(
        ['network' => 'ethereum'],
        ['derivation_index' => 0, 'address' => 'treasury-eth', 'available_funds' => '10.00000000'],
    );

    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'ethereum',
        'gross_amount' => '1.25000000',
        'network_fee' => null,
        'amount_sent' => null,
        'mode' => 'instant',
        'status' => 'pending',
    ]);

    $broadcaster = new EthTestBroadcaster;
    $broadcaster->fee = '0.25000000';

    (new WithdrawalProcessor($broadcaster))->process();

    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->amount_sent)->toBe('0.95000000')
        ->and(TreasuryWallet::where('network', 'ethereum')->first()->available_funds)->toBe('8.75000000');
});

test('usdc erc20 amounts parse at 6 decimals via the registry provider', function () {
    BlockchainScanState::query()->create(['network' => 'usdc_erc20', 'last_scanned_block' => 200]);

    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);
    $topic = '0x'.str_pad('1', 64, '0', STR_PAD_LEFT);

    Http::fake(fn (Request $request) => $request->data()['method'] === 'eth_blockNumber'
        ? Http::response(['jsonrpc' => '2.0', 'result' => '0x400', 'id' => 2])
        : Http::response(['jsonrpc' => '2.0', 'result' => [[
            'blockNumber' => '0x300',
            'transactionHash' => '0xusdc-tx',
            'data' => '0x989680', // 10e6 = 10 USDC
            'topics' => ['', '', $topic],
        ]], 'id' => 1]));

    $provider = new EvmLogsProvider(
        network: 'usdc_erc20',
        contract: '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48',
        rpcUrl: 'https://eth-rpc.test',
        tokenDecimals: Network::tokenDecimals('usdc_erc20'),
    );

    $transactions = $provider->fetchTransactions([$address]);

    expect(Network::tokenDecimals('usdc_erc20'))->toBe(6)
        ->and($transactions)->toHaveCount(1)
        ->and($transactions[0]->amount)->toBe('10.00000000');
});

test('usdc erc20 is swept alongside usdt erc20 with one shared top-up', function () {
    [$owner, $customer] = ethOwner();
    $usdt = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20', 'address' => '0xabc123', 'derivation_index' => 3]);
    $usdc = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdc_erc20', 'address' => '0xabc123', 'derivation_index' => 3]);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);
    TreasuryWallet::factory()->create(['network' => 'usdc_erc20', 'derivation_index' => 0]);

    PlatformSettings::networkSetting('usdt_erc20')->update(['sweep_min_usd' => '0.00']);
    UsdValuation::factory()->create(['network' => 'usdt_erc20', 'conversion_value' => '1.000000']);
    UsdValuation::factory()->create(['network' => 'usdc_erc20', 'conversion_value' => '1.000000']);

    Deposit::factory()->create([
        'deposit_address_id' => $usdt->id, 'customer_id' => $customer->id, 'user_id' => $owner->id,
        'network' => 'usdt_erc20', 'gross_amount' => '10.00000000', 'status' => 'credited', 'credited_at' => now(),
    ]);
    Deposit::factory()->create([
        'deposit_address_id' => $usdc->id, 'customer_id' => $customer->id, 'user_id' => $owner->id,
        'network' => 'usdc_erc20', 'gross_amount' => '5.00000000', 'status' => 'credited', 'credited_at' => now(),
    ]);

    $broadcaster = new EthTestBroadcaster;
    $broadcaster->recipientBalance = '0.00000000';

    $sweeper = new TreasurySweepService($broadcaster);
    $sweeper->sweep();

    expect(GasTopup::count())->toBe(1)
        ->and($broadcaster->topupCalls)->toHaveCount(1);

    $broadcaster->topupReceiptStatus = 'confirmed';
    $broadcaster->recipientBalance = '0.00100000';
    $sweeper->sweep();

    expect($broadcaster->sweptNetworks)->toContain('usdt_erc20')
        ->and($broadcaster->sweptNetworks)->toContain('usdc_erc20')
        ->and($broadcaster->topupCalls)->toHaveCount(1);
});

test('etherscan polling throttles to five requests per second', function () {
    $sleeps = [];
    $addresses = [
        '0x'.str_pad('1', 40, '0', STR_PAD_LEFT),
        '0x'.str_pad('2', 40, '0', STR_PAD_LEFT),
        '0x'.str_pad('3', 40, '0', STR_PAD_LEFT),
    ];

    $requests = [];
    $provider = etherscanProvider([], $requests, function (int $us) use (&$sleeps): void {
        $sleeps[] = $us;
    });

    $provider->fetchTransactions($addresses);

    expect($sleeps)->toHaveCount(2)
        // Computed wait = interval - real elapsed, so any positive sleep within
        // the interval proves the throttle engaged without timing fragility.
        ->and($sleeps[0])->toBeGreaterThan(0)->toBeLessThanOrEqual(200_000)
        ->and($sleeps[1])->toBeGreaterThan(0)->toBeLessThanOrEqual(200_000)
        ->and($requests)->toHaveCount(3);
});

test('etherscan rate limit raises a provider error and cools the scanner down', function () {
    Http::fake(fn () => Http::response([
        'status' => '0',
        'message' => 'NOTOK',
        'result' => 'Max rate limit reached',
    ]));

    $provider = new EtherscanNativeProvider('ethereum', 'test-key', 1, 'https://etherscan.test/api');
    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);

    expect(fn () => $provider->fetchTransactions([$address]))->toThrow(InvalidArgumentException::class);

    $customer = Customer::factory()->create();
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'ethereum', 'address' => $address]);
    config(['networks.networks.ethereum.scan_interval' => 0]);

    (new DepositScanner([new EtherscanNativeProvider('ethereum', 'test-key', 1, 'https://etherscan.test/api')]))->scan();

    $state = BlockchainScanState::where('network', 'ethereum')->first();
    expect($state->consecutive_failures)->toBe(1)
        ->and($state->cooldown_until)->not->toBeNull();
});

test('service provider builds the etherscan native provider for ethereum and evm logs for usdc erc20', function () {
    $sp = new AppServiceProvider(app());
    $method = new ReflectionMethod($sp, 'makeBlockchainProvider');

    expect($method->invoke($sp, 'ethereum'))->toBeInstanceOf(EtherscanNativeProvider::class)
        ->and($method->invoke($sp, 'usdc_erc20'))->toBeInstanceOf(EvmLogsProvider::class);
});

test('sync-networks shares the evm address for ethereum and usdc erc20 without a new gas policy', function () {
    config([
        'networks.networks.ethereum.enabled' => true,
        'networks.networks.usdc_erc20.enabled' => true,
        'blockchain.bitcoin.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
        'blockchain.usdt_trc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
        'blockchain.usdt_erc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
    ]);

    Artisan::call('app:sync-networks');

    $evm = TreasuryWallet::whereIn('network', ['usdt_erc20', 'usdc_erc20', 'ethereum'])->get();
    expect($evm)->toHaveCount(3)
        ->and($evm->pluck('derivation_index')->unique()->values()->all())->toBe([0])
        ->and($evm->pluck('address')->unique())->toHaveCount(1);

    // Only the shared native_eth policy — nothing keyed by ethereum/usdc_erc20.
    expect(GasPolicy::whereIn('network', ['ethereum', 'usdc_erc20'])->count())->toBe(0)
        ->and(GasPolicy::where('network', 'native_eth')->exists())->toBeTrue();
});
