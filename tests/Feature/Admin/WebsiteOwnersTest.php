<?php

use App\Livewire\Admin\WebsiteOwners;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\UsdValuation;
use App\Models\User;
use Livewire\Livewire;

test('an admin can view the website owners list', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'email' => 'owner@example.com']);

    $this->actingAs($admin)
        ->get(route('admin.owners'))
        ->assertOk()
        ->assertSee('Website Owners')
        ->assertSee($owner->email);
});

test('owner list shows earned and balance USD totals', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'email' => 'rich@example.com']);

    UsdValuation::create(['network' => 'usdt_trc20', 'conversion_value' => '1.000000']);
    Balance::factory()->create(['user_id' => $owner->id, 'network' => 'usdt_trc20', 'amount' => '150.00000000']);

    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
    Deposit::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'deposit_address_id' => $address->id,
        'network' => 'usdt_trc20',
        'status' => 'credited',
        'usd_value' => '245.50',
    ]);
    Deposit::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'deposit_address_id' => $address->id,
        'network' => 'usdt_trc20',
        'status' => 'detected',
        'usd_value' => '999.00',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.owners'))
        ->assertOk()
        ->assertSee('Total earned')
        ->assertSee('$245.50')
        ->assertSee('$150.00')
        ->assertSee('Active');
});

test('admin sees the real customer count for an owner with customers', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $owner = User::factory()->create(['role' => 'owner', 'email' => 'busy@example.com']);

    Customer::factory()->count(3)->create(['user_id' => $owner->id]);

    Livewire::actingAs($admin)
        ->test(WebsiteOwners::class)
        ->assertViewHas('owners', fn ($owners) => (int) $owners->firstWhere('email', 'busy@example.com')->customers_count === 3);
});

test('owners sort by current balance descending by default', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $poor = User::factory()->create(['role' => 'owner', 'email' => 'poor@example.com']);
    $rich = User::factory()->create(['role' => 'owner', 'email' => 'rich@example.com']);

    UsdValuation::create(['network' => 'usdt_trc20', 'conversion_value' => '1.000000']);
    Balance::factory()->create(['user_id' => $poor->id, 'network' => 'usdt_trc20', 'amount' => '10.00000000']);
    Balance::factory()->create(['user_id' => $rich->id, 'network' => 'usdt_trc20', 'amount' => '500.00000000']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwners::class)
        ->assertSeeInOrder(['rich@example.com', 'poor@example.com']);
});

test('owners can be sorted by total earned', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    $small = User::factory()->create(['role' => 'owner', 'email' => 'small@example.com']);
    $big = User::factory()->create(['role' => 'owner', 'email' => 'big@example.com']);

    foreach ([[$small, '10.00'], [$big, '1000.00']] as [$owner, $usd]) {
        $customer = Customer::factory()->create(['user_id' => $owner->id]);
        $address = DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_trc20']);
        Deposit::factory()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'deposit_address_id' => $address->id,
            'network' => 'usdt_trc20',
            'status' => 'credited',
            'usd_value' => $usd,
        ]);
    }

    Livewire::actingAs($admin)
        ->test(WebsiteOwners::class)
        ->call('sort', 'earned_usd')
        ->assertSet('sort', 'earned_usd')
        ->assertSet('direction', 'desc')
        ->assertSeeInOrder(['big@example.com', 'small@example.com'])
        ->call('sort', 'earned_usd')
        ->assertSet('direction', 'asc')
        ->assertSeeInOrder(['small@example.com', 'big@example.com']);
});

test('owners can be searched by email', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    User::factory()->create(['role' => 'owner', 'email' => 'alice@shop.com']);
    User::factory()->create(['role' => 'owner', 'email' => 'bob@shop.com']);

    Livewire::actingAs($admin)
        ->test(WebsiteOwners::class)
        ->set('search', 'alice')
        ->assertSee('alice@shop.com')
        ->assertDontSee('bob@shop.com');
});

test('owners cannot access the admin owners list', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('admin.owners'))
        ->assertForbidden();
});

test('guests are redirected to signin', function () {
    $this->get(route('admin.owners'))->assertRedirect(route('signin'));
});

test('error state renders a callout and retry resets to normal', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WebsiteOwners::class)
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load website owners')
        ->call('retry')
        ->assertSet('uiState', 'normal');
});
