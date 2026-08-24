<?php

use App\Livewire\Dashboard\WebhookSettings;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Livewire\Livewire;

test('an owner can view the webhook settings page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('webhook-settings'))
        ->assertOk()
        ->assertSee('Webhook Settings', false)
        ->assertSee('Save Webhook Endpoint', false);
});

test('an owner can save webhook settings', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->set('webhookUrl', 'example.com/webhooks/samedepo')
        ->set('eventCreditedDeposit', true)
        ->set('eventWithdrawalStatus', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Webhook endpoint saved', false);

    $this->assertDatabaseHas('webhook_endpoints', [
        'user_id' => $owner->id,
        'url' => 'https://example.com/webhooks/samedepo',
    ]);

    $endpoint = WebhookEndpoint::where('user_id', $owner->id)->first();
    expect($endpoint->enabled_events)->toContain('deposit.credited', 'withdrawal.status');
});

test('webhook url must use https', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->set('webhookUrl', 'not a url')
        ->call('save')
        ->assertSee('Webhook URL must use https://', false);

    $this->assertDatabaseMissing('webhook_endpoints', ['user_id' => $owner->id]);
});

test('existing webhook settings are loaded', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    WebhookEndpoint::factory()->create([
        'user_id' => $owner->id,
        'url' => 'https://existing.example.com/webhook',
        'enabled_events' => ['deposit.credited'],
    ]);

    Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->assertSet('webhookUrl', 'existing.example.com/webhook')
        ->assertSet('eventCreditedDeposit', true)
        ->assertSet('eventWithdrawalStatus', false);
});

test('error state renders a callout and retry resets to normal', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->set('uiState', 'error')
        ->assertSee('Couldn\'t load webhook settings')
        ->call('retry')
        ->assertSet('uiState', 'normal')
        ->assertDontSee('Couldn\'t load webhook settings');
});

test('guests are redirected to signin', function () {
    $this->get(route('webhook-settings'))->assertRedirect(route('signin'));
});

test('admins cannot access webhook settings', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('webhook-settings'))
        ->assertForbidden();
});
