<?php

use App\Livewire\Dashboard\Customers;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

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
