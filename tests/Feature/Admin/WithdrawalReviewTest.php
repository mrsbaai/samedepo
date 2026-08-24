<?php

use App\Livewire\Admin\WithdrawalReview;
use App\Models\Balance;
use App\Models\User;
use App\Models\Withdrawal;
use Livewire\Livewire;

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
