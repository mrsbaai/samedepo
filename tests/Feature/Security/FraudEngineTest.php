<?php

use App\Fraud\FraudEngine;
use App\Fraud\Models\Device;
use App\Fraud\Models\EntityLink;
use App\Fraud\Models\FraudAlert;
use App\Fraud\Models\FraudLevelPolicy;
use App\Fraud\Models\FraudMetricSetting;
use App\Fraud\Models\UserRisk;
use App\Models\User;
use App\Notifications\FraudLevelAlert;
use App\Security\Blocklist\DeviceBlocklist;
use App\Security\Blocklist\IpBlocklist;
use Illuminate\Support\Facades\Notification;

function shareDevice(User ...$users): Device
{
    $device = Device::query()->create(['fingerprint' => 'shared1234567890']);
    $device->users()->attach(collect($users)->pluck('id'));

    return $device;
}

test('a user with no signals scores zero and stays low', function () {
    $user = User::factory()->create();

    $risk = app(FraudEngine::class)->evaluate($user);

    expect($risk->score)->toBe(0)->and($risk->level)->toBe('low');
});

test('multiple accounts on the same device raise the fraud score', function () {
    [$userA, $userB] = User::factory()->count(2)->create();
    shareDevice($userA, $userB);

    $risk = app(FraudEngine::class)->evaluate($userA);

    expect($risk->score)->toBe(40)
        ->and($risk->level)->toBe('medium')
        ->and(collect($risk->breakdown)->pluck('key'))->toContain('multiple_accounts_same_device');
});

test('shared devices create entity links in both directions', function () {
    [$userA, $userB] = User::factory()->count(2)->create();
    shareDevice($userA, $userB);

    app(FraudEngine::class)->evaluate($userA);

    expect(EntityLink::query()->where('user_id', $userA->id)->where('linked_user_id', $userB->id)->exists())->toBeTrue()
        ->and(EntityLink::query()->where('user_id', $userB->id)->where('linked_user_id', $userA->id)->exists())->toBeTrue();
});

test('disabled metrics do not contribute to the score', function () {
    [$userA, $userB] = User::factory()->count(2)->create();
    shareDevice($userA, $userB);

    FraudMetricSetting::query()->create(['key' => 'multiple_accounts_same_device', 'enabled' => false, 'weight' => 40]);

    expect(app(FraudEngine::class)->evaluate($userA)->score)->toBe(0);
});

test('metric weights are configurable', function () {
    [$userA, $userB] = User::factory()->count(2)->create();
    shareDevice($userA, $userB);

    FraudMetricSetting::query()->create(['key' => 'multiple_accounts_same_device', 'enabled' => true, 'weight' => 90]);

    $risk = app(FraudEngine::class)->evaluate($userA);

    expect($risk->score)->toBe(90)->and($risk->level)->toBe('critical');
});

test('reaching critical applies the policy: user blocked, device and ip blocked, admin notified', function () {
    Notification::fake();

    $admin = User::factory()->create(['is_admin' => true]);
    [$userA, $userB] = User::factory()->count(2)->create();
    $device = shareDevice($userA, $userB);
    $userA->ips()->create(['ip_address' => '203.0.113.7']);

    $userB->forceFill(['fraud_status' => 'blocked'])->save();

    // Link exists from a previous evaluation, then re-evaluate:
    app(FraudEngine::class)->evaluate($userA); // creates links (score 40 + 0)
    $risk = app(FraudEngine::class)->evaluate($userA); // now previous_fraud_connection fires (+80)

    expect($risk->score)->toBeGreaterThanOrEqual(80)
        ->and($risk->level)->toBe('critical')
        ->and($userA->fresh()->fraud_status)->toBe('blocked')
        ->and(DeviceBlocklist::isBlocked($device->fingerprint))->toBeTrue()
        ->and(IpBlocklist::isBlocked('203.0.113.7'))->toBeTrue()
        ->and(FraudAlert::query()->where('user_id', $userA->id)->exists())->toBeTrue();

    Notification::assertSentTo($admin, FraudLevelAlert::class);
});

test('fraud engine blocks are lifted when the level drops but manual blocks stay', function () {
    $user = User::factory()->create();
    $device = Device::query()->create(['fingerprint' => 'droplevel1234567']);
    $device->users()->attach($user->id);

    DeviceBlocklist::block($device->fingerprint, 'by fraud', 'fraud_engine');
    DeviceBlocklist::block('manualdevice1234', 'by admin', 'manual');
    UserRisk::query()->create(['user_id' => $user->id, 'score' => 90, 'level' => 'critical']);

    app(FraudEngine::class)->evaluate($user); // score drops to 0 → low

    expect(DeviceBlocklist::isBlocked($device->fingerprint))->toBeFalse()
        ->and(DeviceBlocklist::isBlocked('manualdevice1234'))->toBeTrue()
        ->and($user->fresh()->fraud_status)->toBe('active');
});

test('a fraud-blocked user is signed out and forbidden', function () {
    $user = User::factory()->create();
    $user->forceFill(['fraud_status' => 'blocked'])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    $this->assertGuest();
});

test('fraud level policies have sane defaults', function () {
    $critical = FraudLevelPolicy::forLevel('critical');
    $low = FraudLevelPolicy::forLevel('low');

    expect($critical->user_status)->toBe('blocked')
        ->and($critical->block_ip)->toBeTrue()
        ->and($critical->notify_admin)->toBeTrue()
        ->and($low->user_status)->toBe('active')
        ->and($low->block_ip)->toBeFalse();
});
