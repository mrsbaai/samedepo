<?php

use App\Livewire\Admin\WithdrawalReview;
use App\Models\Balance;
use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
});

test('the admin review shows the same live buffered fee the owner sees', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'gross_amount' => '100.00000000',
        'status' => 'pending',
    ]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => '1.00']);
    UsdValuation::factory()->create(['network' => 'native_trx', 'conversion_value' => '0.33']);
    PlatformSettings::instance()->update(['withdrawal_fee_buffer_percent' => '20.00']);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->once()->with('usdt_trc20', true)->andReturn('5.00000000');
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($admin)
        ->test(WithdrawalReview::class, ['withdrawal' => $withdrawal->id])
        ->assertSee('1.98 USDT')
        ->assertSee('98.02 USDT')
        ->assertDontSee('1.00 USDT')
        ->assertDontSee('99.00 USDT');
});

test('the admin review says so when the fee estimate is unavailable', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_erc20',
        'gross_amount' => '72.23000000',
        'status' => 'pending',
    ]);

    $broadcaster = Mockery::mock(BlockchainBroadcaster::class);
    $broadcaster->shouldReceive('estimateFee')->andThrow(new RuntimeException('signer unavailable'));
    app()->instance(BlockchainBroadcaster::class, $broadcaster);

    Livewire::actingAs($admin)
        ->test(WithdrawalReview::class, ['withdrawal' => $withdrawal->id])
        ->assertSee('Fee estimate unavailable')
        ->assertDontSee('5.00 USDT')
        ->assertDontSee('67.23 USDT');
});

test('an admin can view a pending withdrawal review', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => 0.4821,
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.withdrawals.show', $withdrawal))
        ->assertOk()
        ->assertSee($owner->email)
        ->assertSee('Bitcoin')
        ->assertSee('0.48210000 BTC')
        ->assertSee('Pending')
        ->assertSee($withdrawal->destination_address);
});

test('an admin can approve a pending withdrawal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => 0.4821,
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)
        ->test(WithdrawalReview::class, ['withdrawal' => $withdrawal->id])
        ->call('confirmApprove')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSee('Withdrawal approved');

    $fresh = $withdrawal->fresh();
    expect($fresh->status)->toBe('approved');
    expect($fresh->decided_by)->toBe($admin->id);
    expect($fresh->decided_at)->not->toBeNull();
});

test('an admin can deny a pending withdrawal and return the balance', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => 0.4821,
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)
        ->test(WithdrawalReview::class, ['withdrawal' => $withdrawal->id])
        ->call('confirmDeny')
        ->call('deny')
        ->call('deny')
        ->assertHasNoErrors()
        ->assertSee('Withdrawal denied');

    $fresh = $withdrawal->fresh();
    expect($fresh->status)->toBe('denied');
    expect($fresh->decided_by)->toBe($admin->id);

    $balance = Balance::query()
        ->withoutGlobalScope('owner')
        ->where('user_id', $owner->id)
        ->where('network', 'bitcoin')
        ->first();
    expect($balance)->not->toBeNull();
    expect((float) $balance->amount)->toBe(0.4821);
});

test('non-existent withdrawal shows not found', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.withdrawals.show', 99999))
        ->assertOk()
        ->assertSee('Withdrawal not found')
        ->assertSee('Back to Queue');
});

test('already decided withdrawal shows not found', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'status' => 'approved',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.withdrawals.show', $withdrawal))
        ->assertOk()
        ->assertSee('Withdrawal not found');
});

test('owners cannot access withdrawal review', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'status' => 'pending',
    ]);

    $this->actingAs($owner)
        ->get(route('admin.withdrawals.show', $withdrawal))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'status' => 'pending',
    ]);

    $this->get(route('admin.withdrawals.show', $withdrawal))->assertRedirect(route('signin'));
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)
        ->test(WithdrawalReview::class, ['withdrawal' => $withdrawal->id])
        ->set('uiState', 'error')
        ->assertSee("Couldn't load withdrawal")
        ->call('retry')
        ->assertSet('uiState', 'normal');
});
