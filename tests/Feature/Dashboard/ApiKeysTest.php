<?php

use App\Livewire\Dashboard\ApiKeys;
use App\Models\ApiKey;
use App\Models\User;
use Livewire\Livewire;

test('an owner can view the api keys page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('api-keys'))
        ->assertOk()
        ->assertSee('API Keys', false);
});

test('an owner can generate an api key', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(ApiKeys::class)
        ->set('newKeyName', 'Production website')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee('API key generated for Production website', false)
        ->assertSee('sm_api_', false);

    $this->assertDatabaseHas('api_keys', [
        'user_id' => $owner->id,
        'name' => 'Production website',
        'status' => 'active',
    ]);
});

test('a key name is required to generate a key', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(ApiKeys::class)
        ->call('generate')
        ->assertHasErrors(['newKeyName' => 'required']);
});

test('an owner sees their active and revoked keys', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    ApiKey::factory()->create([
        'user_id' => $owner->id,
        'status' => 'active',
        'name' => 'Active key',
    ]);

    ApiKey::factory()->create([
        'user_id' => $owner->id,
        'status' => 'revoked',
        'name' => 'Revoked key',
        'revoked_at' => now(),
    ]);

    Livewire::actingAs($owner)
        ->test(ApiKeys::class)
        ->assertSee('Active key', false)
        ->assertSee('Revoked key', false)
        ->assertSee('Active', false)
        ->assertSee('Revoked', false);
});

test('an owner can revoke a key', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $key = ApiKey::factory()->create([
        'user_id' => $owner->id,
        'status' => 'active',
    ]);

    Livewire::actingAs($owner)
        ->test(ApiKeys::class)
        ->call('confirmRevoke', $key->id)
        ->assertSet('showRevokeModal', true)
        ->call('revoke')
        ->assertSet('showRevokeModal', false);

    expect($key->fresh()->status)->toBe('revoked');
});

test('an owner can replace a key', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $key = ApiKey::factory()->create([
        'user_id' => $owner->id,
        'status' => 'active',
    ]);

    Livewire::actingAs($owner)
        ->test(ApiKeys::class)
        ->call('confirmReplace', $key->id)
        ->assertSet('showReplaceModal', true)
        ->call('replace')
        ->assertSet('showReplaceModal', false)
        ->assertSee('API key replaced', false);

    expect($key->fresh()->status)->toBe('revoked');
    expect(ApiKey::where('user_id', $owner->id)->where('status', 'active')->count())->toBe(1);
});

test('an owner cannot see another owners keys', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $other = User::factory()->create(['role' => 'owner']);

    ApiKey::factory()->create([
        'user_id' => $other->id,
        'name' => 'Other key',
    ]);

    Livewire::actingAs($owner)
        ->test(ApiKeys::class)
        ->assertDontSee('Other key', false);
});

test('guests are redirected to signin', function () {
    $this->get(route('api-keys'))->assertRedirect(route('signin'));
});
