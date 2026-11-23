<?php

use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\LedgerEntry;
use App\Models\TreasurySweep;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $xpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';

    config([
        'blockchain.bitcoin.xpub' => $xpub,
        'blockchain.usdt_trc20.xpub' => $xpub,
        'blockchain.usdt_erc20.xpub' => $xpub,
    ]);
});

function sharedAddressFixture(): array
{
    // The unique (network, address) index ships in a later deploy — the repair
    // runs before it, so drop it here to recreate the pre-migration state.
    Schema::table('deposit_addresses', function (Blueprint $table) {
        $table->dropUnique(['network', 'address']);
    });

    $ownerA = User::factory()->create(['role' => 'owner']);
    $customerA = Customer::factory()->create(['user_id' => $ownerA->id]);

    $ownerB = User::factory()->create(['role' => 'owner']);
    $customerB = Customer::factory()->create(['user_id' => $ownerB->id]);

    // Both customers share the same usdt_trc20 address at index 1.
    $addrA = DepositAddress::factory()->create([
        'customer_id' => $customerA->id,
        'network' => 'usdt_trc20',
        'address' => 'TSHARED',
        'derivation_index' => 1,
    ]);
    $addrB = DepositAddress::factory()->create([
        'customer_id' => $customerB->id,
        'network' => 'usdt_trc20',
        'address' => 'TSHARED',
        'derivation_index' => 1,
    ]);
    // B also has an unrelated bitcoin row that must be re-anchored too.
    $btcB = DepositAddress::factory()->create([
        'customer_id' => $customerB->id,
        'network' => 'bitcoin',
        'address' => 'BTC-B',
        'derivation_index' => 1,
    ]);

    // Original deposits on A's row (the keeper).
    $depXA = Deposit::factory()->create([
        'deposit_address_id' => $addrA->id,
        'customer_id' => $customerA->id,
        'user_id' => $ownerA->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-X',
        'gross_amount' => '100.00000000',
        'credited_amount' => '98.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);
    $depYA = Deposit::factory()->create([
        'deposit_address_id' => $addrA->id,
        'customer_id' => $customerA->id,
        'user_id' => $ownerA->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-Y',
        'gross_amount' => '60.00000000',
        'credited_amount' => '58.80000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    // B's duplicates of the same transactions.
    $depXB = Deposit::factory()->create([
        'deposit_address_id' => $addrB->id,
        'customer_id' => $customerB->id,
        'user_id' => $ownerB->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-X',
        'gross_amount' => '100.00000000',
        'credited_amount' => '98.00000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);
    $depYB = Deposit::factory()->create([
        'deposit_address_id' => $addrB->id,
        'customer_id' => $customerB->id,
        'user_id' => $ownerB->id,
        'network' => 'usdt_trc20',
        'tx_hash' => 'tx-Y',
        'gross_amount' => '60.00000000',
        'credited_amount' => '58.80000000',
        'status' => 'credited',
        'credited_at' => now(),
    ]);

    // B's balance only covers the first duplicate — the second drives it negative.
    Balance::factory()->create([
        'user_id' => $ownerB->id,
        'network' => 'usdt_trc20',
        'amount' => '98.00000000',
    ]);

    $sweep = TreasurySweep::create([
        'deposit_address_id' => $addrB->id,
        'network' => 'usdt_trc20',
        'amount' => '50.00000000',
        'status' => 'pending',
    ]);

    return compact('ownerA', 'customerA', 'ownerB', 'customerB', 'addrA', 'addrB', 'btcB', 'depXA', 'depYA', 'depXB', 'depYB', 'sweep');
}

test('dry run reports the plan and changes nothing', function () {
    $f = sharedAddressFixture();
    $ledgerCount = LedgerEntry::count();

    $this->artisan('deposits:repair-shared-addresses')
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('TSHARED')
        ->expectsOutputToContain('new derivation index')
        ->assertSuccessful();

    expect($f['depXB']->fresh()->status)->toBe('credited');
    expect(Balance::withoutGlobalScopes()->where('user_id', $f['ownerB']->id)->value('amount'))->toBe('98.00000000');
    expect(LedgerEntry::count())->toBe($ledgerCount);
    expect($f['sweep']->fresh()->status)->toBe('pending');
    expect($f['addrB']->fresh()->address)->toBe('TSHARED');
    expect($f['ownerB']->fresh()->withdrawal_mode)->not->toBe('approval');
});

test('--apply reverses duplicates, writes off negative balances, reassigns addresses, and is idempotent', function () {
    $f = sharedAddressFixture();

    $this->artisan('deposits:repair-shared-addresses', ['--apply' => true])->assertSuccessful();

    // B's duplicates reversed; A's originals untouched.
    expect($f['depXB']->fresh()->status)->toBe('reversed');
    expect($f['depYB']->fresh()->status)->toBe('reversed');
    expect($f['depXA']->fresh()->status)->toBe('credited');
    expect($f['depYA']->fresh()->status)->toBe('credited');

    // Reversal ledger entries on B.
    $reversals = LedgerEntry::withoutGlobalScopes()
        ->where('user_id', $f['ownerB']->id)
        ->where('reason', 'duplicate_credit_reversal')
        ->orderBy('id')->get();
    expect($reversals)->toHaveCount(2);
    expect($reversals->pluck('deposit_id')->sort()->values()->all())
        ->toBe([$f['depXB']->id, $f['depYB']->id]);
    expect($reversals[0]->amount)->toBe('-98.00000000');
    expect($reversals[1]->amount)->toBe('-58.80000000');

    // 98 - 98 - 58.8 = -58.8 → write_off brings balance back to 0.
    $balance = Balance::withoutGlobalScopes()->where('user_id', $f['ownerB']->id)->where('network', 'usdt_trc20')->first();
    expect($balance->amount)->toBe('0.00000000');

    $writeOff = LedgerEntry::withoutGlobalScopes()
        ->where('user_id', $f['ownerB']->id)->where('reason', 'write_off')->sole();
    expect($writeOff->amount)->toBe('58.80000000');

    // A's balances/ledger untouched.
    expect(LedgerEntry::withoutGlobalScopes()->where('user_id', $f['ownerA']->id)->count())->toBe(0);

    // All of B's address rows share one fresh index and generated addresses.
    $bRows = DepositAddress::withoutGlobalScopes()->where('customer_id', $f['customerB']->id)->get();
    expect($bRows->pluck('derivation_index')->unique())->toHaveCount(1);
    $newIndex = $bRows->first()->derivation_index;
    expect($newIndex)->toBeGreaterThan(1);
    expect($bRows->where('network', 'usdt_trc20')->first()->address)->not->toBe('TSHARED');
    expect($bRows->where('network', 'bitcoin')->first()->address)->not->toBe('BTC-B');

    // A's rows untouched.
    expect($f['addrA']->fresh()->address)->toBe('TSHARED');

    // No shared (network, address) groups remain.
    $shared = DB::table('deposit_addresses')
        ->select('network', 'address')
        ->groupBy('network', 'address')
        ->havingRaw('COUNT(DISTINCT customer_id) > 1')
        ->count();
    expect($shared)->toBe(0);

    // Sweep failed with the repair message.
    expect($f['sweep']->fresh()->status)->toBe('failed');
    expect($f['sweep']->fresh()->error_message)->toBe('Cancelled: shared-address repair');

    // B restricted to approval withdrawals.
    expect($f['ownerB']->fresh()->withdrawal_mode)->toBe('approval');
    expect($f['ownerA']->fresh()->withdrawal_mode)->not->toBe('approval');

    $ledgerCount = LedgerEntry::count();

    // Second run: nothing left to repair.
    $this->artisan('deposits:repair-shared-addresses', ['--apply' => true])
        ->expectsOutputToContain('No shared (network, address) groups found.')
        ->expectsOutputToContain('No duplicate deposits found.')
        ->assertSuccessful();

    expect(LedgerEntry::count())->toBe($ledgerCount);
    expect($f['depXB']->fresh()->status)->toBe('reversed');
});
