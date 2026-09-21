<?php

use App\Livewire\Dashboard\Withdrawals;
use App\Models\Balance;
use App\Models\User;
use App\Models\Withdrawal;
use Livewire\Livewire;

test('an owner can view the withdrawals page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('withdrawals'))
        ->assertOk()
        ->assertSee('Withdrawals', false);
});

test('the withdrawals table lists every status with its amounts and copyable tx hash', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'pending',
        'gross_amount' => '100.00000000',
    ]);
    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'status' => 'sent',
        'gross_amount' => '0.50000000',
        'network_fee' => '0.00010000',
        'consolidation_fee' => '0.00005000',
        'amount_sent' => '0.49985000',
        'tx_hash' => 'a1b2c3d4e5f6a7b8c9d0',
        'sent_at' => now(),
    ]);
    Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'litecoin',
        'status' => 'denied',
        'gross_amount' => '2.00000000',
    ]);

    $this->actingAs($owner)
        ->get(route('withdrawals'))
        ->assertOk()
        ->assertSee('Pending', false)
        ->assertSee('Sent', false)
        ->assertSee('Denied', false)
        ->assertSee('100.00 USDT', false)
        ->assertSee('0.49985000 BTC', false)
        ->assertSee('a1b2c3d4e5f6a7b8c9d0', false);
});

test('a pending withdrawal can be cancelled and the balance is restored', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => 0]);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'pending',
        'gross_amount' => '250.00000000',
    ]);

    Livewire::actingAs($owner)
        ->test(Withdrawals::class)
        ->assertSee('confirmCancel', false)
        ->call('confirmCancel', $withdrawal->id)
        ->assertSet('showCancelModal', true)
        ->call('cancelWithdrawal')
        ->assertSet('showCancelModal', false)
        ->assertSee('Withdrawal cancelled', false);

    expect($withdrawal->fresh()->status)->toBe('cancelled');
    expect(Balance::first()->amount)->toBe('250.00000000');
});

test('cancelling restores the balance on top of deposits received after the request', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => '40.00000000']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'pending',
        'gross_amount' => '250.00000000',
    ]);

    Livewire::actingAs($owner)
        ->test(Withdrawals::class)
        ->call('confirmCancel', $withdrawal->id)
        ->call('cancelWithdrawal');

    expect(Balance::first()->amount)->toBe('290.00000000');
});

test('non-pending withdrawals have no cancel action and cannot be cancelled', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'sent',
        'gross_amount' => '100.00000000',
    ]);

    Livewire::actingAs($owner)
        ->test(Withdrawals::class)
        ->assertDontSee('confirmCancel', false)
        ->call('confirmCancel', $withdrawal->id)
        ->call('cancelWithdrawal')
        ->assertSet('showCancelModal', false);

    expect($withdrawal->fresh()->status)->toBe('sent');
});

test('an owner only sees their own withdrawals', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $other = User::factory()->create(['role' => 'owner']);

    Withdrawal::factory()->create(['user_id' => $owner->id, 'gross_amount' => '10.00000000', 'tx_hash' => 'ownerhash0001']);
    Withdrawal::factory()->create(['user_id' => $other->id, 'gross_amount' => '99.00000000', 'tx_hash' => 'otherhash0001']);

    Livewire::actingAs($owner)
        ->test(Withdrawals::class)
        ->assertSee('ownerhash', false)
        ->assertDontSee('otherhash', false);
});

test('empty state is shown when there are no withdrawals', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('withdrawals'))
        ->assertOk()
        ->assertSee('No withdrawals yet.', false);
});

test('guests are redirected to signin and admins are forbidden', function () {
    $this->get(route('withdrawals'))->assertRedirect(route('signin'));

    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $this->actingAs($admin)->get(route('withdrawals'))->assertForbidden();
});
