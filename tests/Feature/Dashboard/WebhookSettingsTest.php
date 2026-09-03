<?php

use App\Livewire\Dashboard\WebhookSettings;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\WebhookEndpointFailing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('an owner can view the webhook settings page', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $this->actingAs($owner)
        ->get(route('webhook-settings'))
        ->assertOk()
        ->assertSee('Webhook Settings', false)
        ->assertSee('Save Webhook Endpoint', false)
        ->assertSee('Test Endpoint', false);
});

test('an owner can save webhook settings', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->set('webhookUrl', 'example.com/webhooks/samedepo')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Webhook endpoint saved', false);

    $this->assertDatabaseHas('webhook_endpoints', [
        'user_id' => $owner->id,
        'url' => 'https://example.com/webhooks/samedepo',
    ]);

    $endpoint = WebhookEndpoint::where('user_id', $owner->id)->first();
    expect($endpoint->enabled_events)->toBe(['deposit.credited']);
});

test('saving a new webhook endpoint reveals the generated secret', function () {
    $owner = User::factory()->create(['role' => 'owner']);

    $component = Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->set('webhookUrl', 'example.com/webhooks/samedepo')
        ->call('save');

    $component->assertHasNoErrors();

    $endpoint = WebhookEndpoint::where('user_id', $owner->id)->first();
    $component->assertSet('revealedSecret', $endpoint->secret);
    $component->assertSee($endpoint->secret, false);
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
        ->assertSet('webhookUrl', 'existing.example.com/webhook');
});

test('an owner can test a webhook endpoint', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Http::fake(['https://example.test/webhooks' => Http::response(status: 200)]);
    Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->set('webhookUrl', 'example.test/webhooks')
        ->call('test')
        ->assertHasNoErrors()
        ->assertSee('Test delivery succeeded', false);
});

test('an owner can regenerate the webhook secret', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $endpoint = WebhookEndpoint::factory()->create([
        'user_id' => $owner->id,
        'url' => 'https://existing.example.com/webhook',
        'enabled_events' => ['deposit.credited'],
    ]);
    $oldSecret = $endpoint->secret;

    $component = Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->set('showRegenerateModal', true)
        ->call('regenerate')
        ->assertHasNoErrors();

    $endpoint->refresh();

    expect($endpoint->secret)->not->toBe($oldSecret)
        ->and($endpoint->url)->toBe('https://existing.example.com/webhook')
        ->and($endpoint->enabled_events)->toBe(['deposit.credited'])
        ->and($component->get('revealedSecret'))->toBe($endpoint->secret);
});

test('a failing webhook test shows an error and notifies the owner', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    Notification::fake();
    Http::fake(['https://example.test/webhooks' => Http::response(status: 500)]);

    Livewire::actingAs($owner)
        ->test(WebhookSettings::class)
        ->set('webhookUrl', 'example.test/webhooks')
        ->call('test')
        ->assertSee('Test delivery failed', false);

    Notification::assertSentTo($owner, WebhookEndpointFailing::class);
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
