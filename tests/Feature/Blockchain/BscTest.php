<?php

use App\Livewire\Admin\TreasuryOverview;
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
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\DepositCreditor;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\Providers\EvmLogsProvider;
use App\Services\Blockchain\TreasurySweepService;
use App\Services\Blockchain\WithdrawalProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class BscTestBroadcaster implements BlockchainBroadcaster
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

    /** @var array<int, string> */
    public array $topupNetworks = [];

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

        return ['status' => 'pending', 'fee' => '0.00001000', 'confirmations' => 0];
    }

    public function estimateFee(string $network, bool $tokenTransfer = true): ?string
    {
        return $this->fee;
    }

    public function estimateTransferResources(string $network, bool $tokenTransfer, ?string $destination = null, ?int $sourceIndex = null): ?array
    {
        return $this->fee === null ? null : ['fee' => $this->fee, 'energy' => null];
    }

    public function broadcastPayout(TreasuryPayout $payout): ?string
    {
        return null;
    }

    public function broadcastTopUp(string $network, int $sourceIndex, int $destinationIndex, string $amount, string $fee): ?string
    {
        $this->topupCalls[] = ['amount' => $amount, 'fee' => $fee];
        $this->topupNetworks[] = $network;

        return $this->topupHash;
    }
}

function bscOwner(): array
{
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    PlatformSettings::instance();

    return [$owner, $customer];
}

function bscDepositAddress(int $customerId, string $network, string $address = '0xabc123', int $index = 3): DepositAddress
{
    return DepositAddress::factory()->create([
        'customer_id' => $customerId,
        'network' => $network,
        'address' => $address,
        'derivation_index' => $index,
    ]);
}

function bscDeposit(DepositAddress $address, int $customerId, int $userId, string $amount): Deposit
{
    return Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customerId,
        'user_id' => $userId,
        'network' => $address->network,
        'gross_amount' => $amount,
        'status' => 'credited',
        'credited_at' => now(),
    ]);
}

test('evm logs provider converts 18-decimal raw amounts with bcmath to 8 decimals', function () {
    BlockchainScanState::query()->create(['network' => 'usdt_bep20', 'last_scanned_block' => 200]);

    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);
    $topic = '0x'.str_pad('1', 64, '0', STR_PAD_LEFT);

    Http::fake(fn (Request $request) => $request->data()['method'] === 'eth_blockNumber'
        ? Http::response(['jsonrpc' => '2.0', 'result' => '0x400', 'id' => 2])
        : Http::response(['jsonrpc' => '2.0', 'result' => [[
            'blockNumber' => '0x300',
            'transactionHash' => '0xtx1',
            'data' => '0x4563918244f40000', // 5e18
            'topics' => ['', '', $topic],
        ], [
            'blockNumber' => '0x300',
            'transactionHash' => '0xtx2',
            'data' => '0x112210f47de98115', // 1234567890123456789
            'topics' => ['', '', $topic],
        ]], 'id' => 1]));

    $provider = new EvmLogsProvider(
        network: 'usdt_bep20',
        contract: '0x55d398326f99059ff775485246999027b3197955',
        rpcUrl: 'https://bsc-rpc.test',
        tokenDecimals: 18,
    );

    $transactions = $provider->fetchTransactions([$address]);

    expect($transactions)->toHaveCount(2)
        ->and($transactions[0]->amount)->toBe('5.00000000')
        ->and($transactions[1]->amount)->toBe('1.23456789');
});

test('bsc scan detects a deposit pending then credits at 15 confirmations', function () {
    [$owner, $customer] = bscOwner();
    $address = '0x'.str_pad('9', 40, '0', STR_PAD_LEFT);
    bscDepositAddress($customer->id, 'usdt_bep20', $address);
    PlatformSettings::networkSetting('usdt_bep20')->update(['min_deposit' => '0.00000000']);
    config(['networks.networks.usdt_bep20.scan_interval' => 0]);

    $currentBlock = 0x400;
    $logBlock = 0x400 - 10; // 11 confirmations
    $topic = '0x'.str_pad('9', 64, '0', STR_PAD_LEFT);

    Http::fake(function (Request $request) use (&$logBlock, $currentBlock, $topic) {
        return $request->data()['method'] === 'eth_blockNumber'
            ? Http::response(['jsonrpc' => '2.0', 'result' => dechex($currentBlock), 'id' => 2])
            : Http::response(['jsonrpc' => '2.0', 'result' => [[
                'blockNumber' => dechex($logBlock),
                'transactionHash' => '0xbsctx',
                'data' => '0x4563918244f40000',
                'topics' => ['', '', $topic],
            ]], 'id' => 1]);
    });

    $provider = fn () => new EvmLogsProvider(
        network: 'usdt_bep20',
        contract: '0x55d398326f99059ff775485246999027b3197955',
        rpcUrl: 'https://bsc-rpc.test',
        tokenDecimals: 18,
    );

    (new DepositScanner([$provider()]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit = Deposit::where('tx_hash', '0xbsctx')->first();
    expect($deposit)->not->toBeNull()
        ->and($deposit->network)->toBe('usdt_bep20')
        ->and($deposit->status)->toBe('pending')
        ->and($deposit->confirmation_count)->toBe(11);

    // Rewind the log to 15 confirmations and rescan the overlapping range.
    $logBlock = 0x400 - 14;
    BlockchainScanState::where('network', 'usdt_bep20')->update(['last_scanned_block' => null]);

    (new DepositScanner([$provider()]))->scan();
    app(DepositCreditor::class)->credit();

    $deposit->refresh();
    expect($deposit->status)->toBe('credited')
        ->and($deposit->confirmation_count)->toBe(15)
        ->and(BlockchainScanState::where('network', 'usdt_bep20')->value('last_scanned_block'))->toBe(0x400);
});

test('evm logs provider scans contiguous chunks within the configured range and ends at the tip', function () {
    // last 688 with overlap 14 → from 675; tip 1024 → 4 chunks of ≤100 blocks.
    BlockchainScanState::query()->create(['network' => 'usdt_bep20', 'last_scanned_block' => 688]);

    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);
    $ranges = [];

    Http::fake(function (Request $request) use (&$ranges) {
        if ($request->data()['method'] === 'eth_blockNumber') {
            return Http::response(['jsonrpc' => '2.0', 'result' => dechex(1024), 'id' => 2]);
        }

        $ranges[] = $request->data()['params'][0];

        return Http::response(['jsonrpc' => '2.0', 'result' => [], 'id' => 1]);
    });

    (new EvmLogsProvider(
        network: 'usdt_bep20',
        contract: '0x55d398326f99059ff775485246999027b3197955',
        rpcUrl: 'https://bsc-rpc.test',
        tokenDecimals: 18,
        blockRange: 100,
    ))->fetchTransactions([$address]);

    expect($ranges)->toHaveCount(4);

    $previousTo = null;
    foreach ($ranges as $params) {
        $from = hexdec($params['fromBlock']);
        $to = hexdec($params['toBlock']);
        expect($to - $from + 1)->toBeLessThanOrEqual(100);
        if ($previousTo !== null) {
            expect($from)->toBe($previousTo + 1);
        }
        $previousTo = $to;
    }

    expect($previousTo)->toBe(1024)
        ->and(BlockchainScanState::where('network', 'usdt_bep20')->value('last_scanned_block'))->toBe(1024);
});

test('evm logs provider stops after maxChunksPerScan calls when far behind', function () {
    // last 100 with overlap 14 → from 87; tip far ahead → capped at 3 chunks.
    BlockchainScanState::query()->create(['network' => 'usdt_bep20', 'last_scanned_block' => 100]);

    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);
    $getLogs = 0;

    Http::fake(function (Request $request) use (&$getLogs) {
        if ($request->data()['method'] === 'eth_blockNumber') {
            return Http::response(['jsonrpc' => '2.0', 'result' => dechex(2000), 'id' => 2]);
        }

        $getLogs++;

        return Http::response(['jsonrpc' => '2.0', 'result' => [], 'id' => 1]);
    });

    (new EvmLogsProvider(
        network: 'usdt_bep20',
        contract: '0x55d398326f99059ff775485246999027b3197955',
        rpcUrl: 'https://bsc-rpc.test',
        tokenDecimals: 18,
        blockRange: 100,
        maxChunksPerScan: 3,
    ))->fetchTransactions([$address]);

    expect($getLogs)->toBe(3)
        ->and(BlockchainScanState::where('network', 'usdt_bep20')->value('last_scanned_block'))->toBe(386);
});

test('evm logs provider returns completed chunks and saved progress when a later chunk fails', function () {
    // last 688 with overlap 14 → from 675; chunk 1 [675,774] succeeds, chunk 2 fails.
    BlockchainScanState::query()->create(['network' => 'usdt_bep20', 'last_scanned_block' => 688]);

    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);
    $topic = '0x'.str_pad('1', 64, '0', STR_PAD_LEFT);
    $getLogs = 0;

    Http::fake(function (Request $request) use (&$getLogs, $topic) {
        if ($request->data()['method'] === 'eth_blockNumber') {
            return Http::response(['jsonrpc' => '2.0', 'result' => dechex(1024), 'id' => 2]);
        }

        $getLogs++;

        if ($getLogs === 2) {
            return Http::response(['jsonrpc' => '2.0', 'error' => ['code' => 35, 'message' => 'range too large'], 'id' => 1]);
        }

        return Http::response(['jsonrpc' => '2.0', 'result' => [[
            'blockNumber' => '0x2a3', // 675
            'transactionHash' => '0xchunk1',
            'data' => '0x4563918244f40000',
            'topics' => ['', '', $topic],
        ]], 'id' => 1]);
    });

    $transactions = (new EvmLogsProvider(
        network: 'usdt_bep20',
        contract: '0x55d398326f99059ff775485246999027b3197955',
        rpcUrl: 'https://bsc-rpc.test',
        tokenDecimals: 18,
        blockRange: 100,
    ))->fetchTransactions([$address]);

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0]->txHash)->toBe('0xchunk1')
        ->and(BlockchainScanState::where('network', 'usdt_bep20')->value('last_scanned_block'))->toBe(774);
});

test('evm logs provider rethrows a first chunk failure without touching scan state', function () {
    BlockchainScanState::query()->create(['network' => 'usdt_bep20', 'last_scanned_block' => 688]);

    $address = '0x'.str_pad('1', 40, '0', STR_PAD_LEFT);

    Http::fake(fn (Request $request) => $request->data()['method'] === 'eth_blockNumber'
        ? Http::response(['jsonrpc' => '2.0', 'result' => dechex(1024), 'id' => 2])
        : Http::response(['jsonrpc' => '2.0', 'error' => ['code' => 35, 'message' => 'range too large'], 'id' => 1]));

    $provider = new EvmLogsProvider(
        network: 'usdt_bep20',
        contract: '0x55d398326f99059ff775485246999027b3197955',
        rpcUrl: 'https://bsc-rpc.test',
        tokenDecimals: 18,
        blockRange: 100,
    );

    expect(fn () => $provider->fetchTransactions([$address]))->toThrow(InvalidArgumentException::class)
        ->and(BlockchainScanState::where('network', 'usdt_bep20')->value('last_scanned_block'))->toBe(688);
});

test('bnb top-up is sized to the buffered need minus the recipient balance', function () {
    [$owner, $customer] = bscOwner();
    $address = bscDepositAddress($customer->id, 'usdt_bep20');
    TreasuryWallet::factory()->create(['network' => 'usdt_bep20', 'derivation_index' => 0]);
    PlatformSettings::networkSetting('usdt_bep20')->update(['sweep_min_usd' => '0.00']);
    bscDeposit($address, $customer->id, $owner->id, '10.00000000');

    $broadcaster = new BscTestBroadcaster;
    $broadcaster->recipientBalance = '0.00003000';
    $broadcaster->sweepHash = null; // never reached while gas is short

    (new TreasurySweepService($broadcaster))->sweep();

    $topup = GasTopup::where('network', 'usdt_bep20')->where('recipient_address', '0xabc123')->first();
    expect($topup)->not->toBeNull()
        ->and($topup->kind)->toBe('topup')
        // need = bufferedFee(0.00012) − balance(0.00003) = 0.00009 < floor → policy top_up_amount.
        ->and($topup->amount)->toBe('0.00020000')
        ->and($broadcaster->topupCalls)->toHaveCount(1);
});

test('stranded bnb on an emptied bsc deposit address is recovered above 0.001', function () {
    config(['networks.networks.usdt_bep20.enabled' => true]);
    [$owner, $customer] = bscOwner();
    bscDepositAddress($customer->id, 'usdt_bep20', '0xabc123', 7);
    $wallet = TreasuryWallet::factory()->create(['network' => 'usdt_bep20', 'derivation_index' => 0]);

    $topup = GasTopup::create([
        'network' => 'usdt_bep20',
        'kind' => 'topup',
        'recipient_address' => '0xabc123',
        'recipient_index' => 7,
        'treasury_wallet_id' => $wallet->id,
        'amount' => '0.00020000',
        'status' => 'confirmed',
        'is_open' => '1',
    ]);

    $broadcaster = new BscTestBroadcaster;
    $broadcaster->tokenBalance = '0.00000000';
    $broadcaster->recipientBalance = '0.00500000';
    $broadcaster->topupReceiptStatus = 'confirmed';

    app()->instance(BlockchainBroadcaster::class, $broadcaster);
    (new GasTreasuryService($broadcaster))->recoverStrandedGas();

    expect($broadcaster->topupCalls)->toHaveCount(1)
        ->and($broadcaster->topupCalls[0]['amount'])->toBe('0.00490000')
        ->and($broadcaster->topupCalls[0]['fee'])->toBe('0.00010000');

    $recovery = GasTopup::where('network', 'usdt_bep20')->where('kind', 'recovery')->first();
    expect($recovery)->not->toBeNull()->and($recovery->amount)->toBe('0.00490000');
});

test('usdc is swept alongside usdt when gas is present and below its own threshold', function () {
    [$owner, $customer] = bscOwner();
    $usdt = bscDepositAddress($customer->id, 'usdt_bep20', '0xabc123', 3);
    $usdc = bscDepositAddress($customer->id, 'usdc_bep20', '0xabc123', 3);
    TreasuryWallet::factory()->create(['network' => 'usdt_bep20', 'derivation_index' => 0]);
    TreasuryWallet::factory()->create(['network' => 'usdc_bep20', 'derivation_index' => 0]);

    PlatformSettings::networkSetting('usdt_bep20')->update(['sweep_min_usd' => '0.00']);
    PlatformSettings::networkSetting('usdc_bep20')->update(['sweep_min_usd' => '10.00']);
    UsdValuation::factory()->create(['network' => 'usdt_bep20', 'conversion_value' => '1.000000']);
    UsdValuation::factory()->create(['network' => 'usdc_bep20', 'conversion_value' => '1.000000']);

    bscDeposit($usdt, $customer->id, $owner->id, '10.00000000');
    bscDeposit($usdc, $customer->id, $owner->id, '5.00000000'); // below its own $10 threshold

    $broadcaster = new BscTestBroadcaster;
    $broadcaster->recipientBalance = '1.00000000'; // gas already present

    (new TreasurySweepService($broadcaster))->sweep();

    expect($broadcaster->sweptNetworks)->toContain('usdt_bep20')
        ->and($broadcaster->sweptNetworks)->toContain('usdc_bep20')
        ->and(GasTopup::count())->toBe(0);

    $usdcSweep = TreasurySweep::where('network', 'usdc_bep20')->first();
    expect($usdcSweep)->not->toBeNull()
        ->and($usdcSweep->tx_hash)->toBe('sweep-tx-123-usdc_bep20')
        ->and($usdcSweep->piggybacked_on_sweep_id)->not->toBeNull();
});

test('one top-up funds the whole piggyback batch for the second token', function () {
    [$owner, $customer] = bscOwner();
    $usdt = bscDepositAddress($customer->id, 'usdt_bep20', '0xabc123', 3);
    $usdc = bscDepositAddress($customer->id, 'usdc_bep20', '0xabc123', 3);
    TreasuryWallet::factory()->create(['network' => 'usdt_bep20', 'derivation_index' => 0]);
    TreasuryWallet::factory()->create(['network' => 'usdc_bep20', 'derivation_index' => 0]);

    PlatformSettings::networkSetting('usdt_bep20')->update(['sweep_min_usd' => '0.00']);
    UsdValuation::factory()->create(['network' => 'usdt_bep20', 'conversion_value' => '1.000000']);
    UsdValuation::factory()->create(['network' => 'usdc_bep20', 'conversion_value' => '1.000000']);

    bscDeposit($usdt, $customer->id, $owner->id, '10.00000000');
    bscDeposit($usdc, $customer->id, $owner->id, '5.00000000');

    $broadcaster = new BscTestBroadcaster;
    $broadcaster->recipientBalance = '0.00000000';

    $sweeper = new TreasurySweepService($broadcaster);
    $sweeper->sweep(); // sends the shared top-up; nothing broadcasts yet

    expect(GasTopup::count())->toBe(1)
        ->and($broadcaster->topupCalls)->toHaveCount(1)
        // sized for 2 buffered token transfers: buffered(0.0001)×2 = 0.00024 > 0.0002 floor
        ->and($broadcaster->topupCalls[0]['amount'])->toBe('0.00024000');

    $broadcaster->topupReceiptStatus = 'confirmed';
    $broadcaster->recipientBalance = '0.00100000'; // the confirmed top-up landed
    $sweeper->sweep(); // top-up confirms → both sweeps broadcast

    expect($broadcaster->sweptNetworks)->toContain('usdt_bep20')
        ->and($broadcaster->sweptNetworks)->toContain('usdc_bep20')
        ->and($broadcaster->topupCalls)->toHaveCount(1)
        ->and(GasTopup::count())->toBe(1);
});

test('piggyback never crosses chains', function () {
    [$owner, $customer] = bscOwner();
    $usdtBsc = bscDepositAddress($customer->id, 'usdt_bep20', '0xabc123', 3);
    $usdtEth = bscDepositAddress($customer->id, 'usdt_erc20', '0xabc123', 3);
    TreasuryWallet::factory()->create(['network' => 'usdt_bep20', 'derivation_index' => 0]);
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0]);

    PlatformSettings::networkSetting('usdt_bep20')->update(['sweep_min_usd' => '0.00']);
    PlatformSettings::networkSetting('usdt_erc20')->update(['sweep_min_usd' => '300.00']);
    UsdValuation::factory()->create(['network' => 'usdt_bep20', 'conversion_value' => '1.000000']);
    UsdValuation::factory()->create(['network' => 'usdt_erc20', 'conversion_value' => '1.000000']);

    bscDeposit($usdtBsc, $customer->id, $owner->id, '10.00000000');
    bscDeposit($usdtEth, $customer->id, $owner->id, '5.00000000');

    $broadcaster = new BscTestBroadcaster;
    $broadcaster->recipientBalance = '1.00000000';

    (new TreasurySweepService($broadcaster))->sweep();

    expect($broadcaster->sweptNetworks)->toContain('usdt_bep20')
        ->and($broadcaster->sweptNetworks)->not->toContain('usdt_erc20')
        ->and(TreasurySweep::where('network', 'usdt_erc20')->exists())->toBeFalse();
});

test('bep20 withdrawal converts the bnb fee into token units and uses the native_bnb gas path', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    TreasuryWallet::firstOrCreate(
        ['network' => 'usdt_bep20'],
        ['derivation_index' => 0, 'address' => 'treasury-bsc', 'available_funds' => '1000.00000000'],
    );

    UsdValuation::factory()->create(['network' => 'native_bnb', 'conversion_value' => '600.000000']);
    UsdValuation::factory()->create(['network' => 'usdt_bep20', 'conversion_value' => '1.000000']);

    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_bep20',
        'gross_amount' => '100.00000000',
        'network_fee' => null,
        'amount_sent' => null,
        'mode' => 'instant',
        'status' => 'pending',
    ]);

    $gas = Mockery::mock(GasTreasuryService::class);
    $gas->shouldReceive('policy')->andReturn(new GasPolicy(['network' => 'native_bnb']));
    $gas->shouldReceive('estimateNeededEnergy')->andReturn(0);
    $gas->shouldReceive('rentalFeeEstimateNative')->andReturn(null);
    $gas->shouldReceive('ensureGasForWithdrawal')->once()->andReturn(true);

    $broadcaster = new BscTestBroadcaster;
    $broadcaster->fee = '0.00030000'; // BNB

    (new WithdrawalProcessor($broadcaster, $gas))->process();

    $withdrawal->refresh();
    // 0.0003 BNB buffered ×1.2 = 0.00036 BNB × $600 = $0.216 → 0.216 USDT
    expect($withdrawal->status)->toBe('sent')
        ->and($withdrawal->network_fee_native)->toBe('0.00036000')
        ->and($withdrawal->network_fee)->toBe('0.21600000')
        ->and($withdrawal->amount_sent)->toBe('99.78400000');
});

test('admin treasury lists the native_bnb gas policy only when a bsc network is enabled', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $component = Livewire::actingAs($admin)->test(TreasuryOverview::class);
    expect($component->instance()->policies)->not->toHaveKey('native_bnb');

    config(['networks.networks.usdt_bep20.enabled' => true]);
    TreasuryWallet::factory()->create(['network' => 'usdt_bep20', 'derivation_index' => 0]);

    $component = Livewire::actingAs($admin)->test(TreasuryOverview::class);
    $policies = $component->instance()->policies;
    expect($policies)->toHaveKey('native_bnb')
        ->and($policies['native_bnb']['top_up_amount'])->toBe('0.00020000')
        ->and($policies['native_bnb']['max_top_up'])->toBe('0.00200000')
        ->and($policies['native_bnb']['reserve_threshold'])->toBe('0.00500000')
        ->and($component->instance()->networkMeta('native_bnb')['symbol'])->toBe('BNB');
});

test('sync-networks shares one evm address across erc20 and bep20 treasury wallets', function () {
    config([
        'networks.networks.usdt_bep20.enabled' => true,
        'networks.networks.usdc_bep20.enabled' => true,
        'blockchain.bitcoin.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
        'blockchain.usdt_trc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
        'blockchain.usdt_erc20.xpub' => 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8',
    ]);

    Artisan::call('app:sync-networks');

    $evm = TreasuryWallet::whereIn('network', ['usdt_erc20', 'usdt_bep20', 'usdc_bep20'])->get();
    expect($evm)->toHaveCount(3)
        ->and($evm->pluck('derivation_index')->unique()->values()->all())->toBe([0])
        ->and($evm->pluck('address')->unique())->toHaveCount(1);

    expect(GasPolicy::where('network', 'native_bnb')->exists())->toBeTrue();
});
