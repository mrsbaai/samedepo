<?php

use App\Livewire\Admin\WithdrawalQueue;
use App\Models\User;
use App\Models\Withdrawal;

test('an admin can view the pending withdrawal queue', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'gross_amount' => 0.4821,
        'status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.withdrawals'))
        ->assertOk()
        ->assertSee($owner->email)
        ->assertSee('Bitcoin')
        ->assertSee('0.48210000 BTC')
        ->assertSee('Pending')
        ->assertSee(route('admin.withdrawals.show', $withdrawal));
});

test('queue lists pending withdrawals oldest first', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner']);
    $oldest = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'bitcoin',
        'status' => 'pending',
        'created_at' => now()->subDay(),
    ]);
    $newest = Withdrawal::factory()->create([
        'user_id' => $owner->id,
        'network' => 'usdt_trc20',
        'status' => 'pending',
        'created_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.withdrawals'));
    $response->assertOk();

    $content = $response->getContent();
    $bitcoinPos = strpos($content, 'Bitcoin');
    $usdtPos = strpos($content, 'USDT (TRC20)');
    expect($bitcoinPos)->toBeLessThan($usdtPos);
});

test('empty state is shown when no pending withdrawals', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.withdrawals'))
        ->assertOk()
        ->assertSee('Nothing pending')
        ->assertSee('Every withdrawal request has been reviewed.');
});

test('owners cannot access the withdrawal queue', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.withdrawals'))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $this->get(route('admin.withdrawals'))->assertRedirect(route('signin'));
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WithdrawalQueue::class)
        ->set('uiState', 'error')
        ->assertSee("Couldn't load withdrawal queue")
        ->call('retry')
        ->assertSet('uiState', 'normal');
});
