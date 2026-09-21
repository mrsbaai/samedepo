<?php

use App\Livewire\Dashboard\Customers;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\User;
use Livewire\Livewire;

function creditDeposit(User $owner, Customer $customer, string $network, string $amount, ?string $usd = null, string $status = 'credited'): Deposit
{
    $address = DepositAddress::firstOrCreate(
        ['customer_id' => $customer->id, 'network' => $network],
        ['address' => fake()->uuid().'-'.$network],
    );

    return Deposit::factory()->create([
        'deposit_address_id' => $address->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'network' => $network,
        'status' => $status,
        'credited_amount' => $status === 'credited' ? $amount : null,
        'usd_value' => $usd,
        'detected_at' => now(),
        'credited_at' => $status === 'credited' ? now() : null,
    ]);
}

test('an authenticated owner can view their customer list', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customers = Customer::factory()->count(2)->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('customers'))
        ->assertOk()
        ->assertSee($customers->first()->customer_reference, false)
        ->assertSee($customers->last()->customer_reference, false);
});

test('search filters customers by customer reference', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $matchingCustomer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-ALPHA']);
    Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-BETA']);

    Livewire::actingAs($owner)
        ->test(Customers::class)
        ->set('search', 'alpha')
        ->assertSee($matchingCustomer->customer_reference, false)
        ->assertDontSee('CUST-BETA', false);
});

test('pagination splits the customer list into pages', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Customer::factory()->count(15)->create(['user_id' => $owner->id]);

    Livewire::actingAs($owner)
        ->test(Customers::class)
        ->assertSet('paginatedCustomers', fn ($paginator) => $paginator->count() === 10)
        ->call('gotoPage', 2)
        ->assertSet('paginatedCustomers', fn ($paginator) => $paginator->count() === 5);
});

test('empty state is shown when the owner has no customers', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('customers'))
        ->assertOk()
        ->assertSee('No customers yet. Customers appear here once you register them through the API.', false);
});

test('empty search state is shown when no customers match the search', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-FOUND']);

    Livewire::actingAs($owner)
        ->test(Customers::class)
        ->set('search', 'nothing-matches')
        ->assertSee('No customers match your search.', false);
});

test('admin users cannot access the customers list', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('customers'))
        ->assertForbidden();
});

test('each customer row links to the customer detail page', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('customers'))
        ->assertOk()
        ->assertSee(route('customers.show', $customer), false);
});

test('a network column appears only when a customer has income on it', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    creditDeposit($owner, $customer, 'usdt_trc20', '50.00', '50.00');

    Livewire::actingAs($owner)
        ->test(Customers::class)
        ->assertSee('USDT (TRC20)', false)
        ->assertDontSee('Bitcoin', false)
        ->assertSee('50.00 USDT', false)
        ->assertSee('$50.00', false);
});

test('the registered column shows relative time with a full date tooltip', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Customer::factory()->create(['user_id' => $owner->id, 'created_at' => now()->subHours(3)]);

    Livewire::actingAs($owner)
        ->test(Customers::class)
        ->assertSee('3 hours ago', false)
        ->assertSee(now()->subHours(3)->format('M j, H:i'), false);
});

test('customers can be sorted by total usd in both directions', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $small = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-SMALL']);
    $big = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-BIG']);
    $none = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-NONE']);
    creditDeposit($owner, $small, 'bitcoin', '0.001', '10.00');
    creditDeposit($owner, $big, 'bitcoin', '0.01', '100.00');

    $component = Livewire::actingAs($owner)->test(Customers::class);

    $component->call('sort', 'total_usd');
    expect($component->instance()->paginatedCustomers->pluck('customer_reference')->all())
        ->toBe(['CUST-BIG', 'CUST-SMALL', 'CUST-NONE']);

    $component->call('sort', 'total_usd');
    expect($component->instance()->paginatedCustomers->pluck('customer_reference')->all())
        ->toBe(['CUST-NONE', 'CUST-SMALL', 'CUST-BIG']);
});

test('customers can be sorted by a single network usd column', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $trc = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-TRC']);
    $btc = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-BTC']);
    creditDeposit($owner, $trc, 'usdt_trc20', '80.00', '80.00');
    creditDeposit($owner, $btc, 'usdt_trc20', '5.00', '5.00');
    creditDeposit($owner, $btc, 'bitcoin', '0.02', '900.00');

    $component = Livewire::actingAs($owner)->test(Customers::class);

    $component->call('sort', 'usd_bitcoin');
    expect($component->instance()->paginatedCustomers->pluck('customer_reference')->first())
        ->toBe('CUST-BTC');

    $component->call('sort', 'usd_usdt_trc20');
    expect($component->instance()->paginatedCustomers->pluck('customer_reference')->first())
        ->toBe('CUST-TRC');
});

test('customers can be sorted by credited deposit count', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $many = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-MANY']);
    $one = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'CUST-ONE']);
    creditDeposit($owner, $many, 'bitcoin', '0.001', '10.00');
    creditDeposit($owner, $many, 'bitcoin', '0.002', '20.00');
    creditDeposit($owner, $one, 'bitcoin', '0.001', '10.00');
    creditDeposit($owner, $one, 'bitcoin', '0.001', null, 'pending');

    $component = Livewire::actingAs($owner)->test(Customers::class);

    $component->call('sort', 'deposits_count');
    expect($component->instance()->paginatedCustomers->pluck('customer_reference')->all())
        ->toBe(['CUST-MANY', 'CUST-ONE']);
});

test('sorting toggles direction on a repeated column and resets on a new column', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Customer::factory()->create(['user_id' => $owner->id]);

    Livewire::actingAs($owner)
        ->test(Customers::class)
        ->assertSet('sort', 'created_at')
        ->assertSet('direction', 'desc')
        ->call('sort', 'created_at')
        ->assertSet('direction', 'asc')
        ->call('sort', 'customer_reference')
        ->assertSet('sort', 'customer_reference')
        ->assertSet('direction', 'asc');
});
