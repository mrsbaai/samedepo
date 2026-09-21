<?php

use App\Livewire\Dashboard\UserDashboard;
use App\Models\Balance;
use App\Models\UsdValuation;
use App\Models\User;
use App\Support\CryptoIcon;
use Livewire\Livewire;

function seedBalances(User $owner): void
{
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'bitcoin', 'amount' => '0.50000000']);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => '100.00000000']);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'usdt_erc20', 'amount' => '50.00000000']);

    UsdValuation::factory()->create(['network' => 'bitcoin', 'conversion_value' => 60000]);
    UsdValuation::factory()->create(['network' => 'usdt_trc20', 'conversion_value' => 1]);
    UsdValuation::factory()->create(['network' => 'usdt_erc20', 'conversion_value' => 1]);
}

test('an authenticated owner can access the dashboard home', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard', false);
});

test('balance cards display each network usd value above its crypto amount and logo', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    seedBalances($owner);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('$30,000.00', false)
        ->assertSee('$100.00', false)
        ->assertSee('$50.00', false)
        ->assertSee('0.50000000 BTC', false)
        ->assertSee('100.00 USDT', false)
        ->assertSee('50.00 USDT', false)
        ->assertSee(CryptoIcon::url('btc'), false)
        ->assertSee(CryptoIcon::url('usdt'), false)
        ->assertSee(CryptoIcon::url('trx'), false)
        ->assertSee(CryptoIcon::url('eth'), false)
        ->assertSee(route('withdraw', ['network' => 'bitcoin']), false)
        ->assertSee(route('withdraw', ['network' => 'usdt-trc20']), false)
        ->assertSee(route('withdraw', ['network' => 'usdt-erc20']), false)
        ->assertSee('Withdraw', false)
        ->assertDontSee('$30,150.00', false);
});

test('error state renders a callout and retry resets to normal', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    seedBalances($owner);

    $this->actingAs($owner)
        ->get(route('dashboard', ['state' => 'error']))
        ->assertOk()
        ->assertSeeText("Couldn't load dashboard data");

    Livewire::actingAs($owner)
        ->test(UserDashboard::class)
        ->set('uiState', 'error')
        ->assertSeeText("Couldn't load dashboard data")
        ->call('retry')
        ->assertSet('uiState', 'normal');
});

test('the deposits page is removed and deposits live in transaction history', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get('/deposits')
        ->assertNotFound();
});

test('only owners can view the dashboard, admins are rejected', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertForbidden();
});
