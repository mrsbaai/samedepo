<?php

use App\Fraud\Models\Device;
use App\Fraud\Models\FraudLevelPolicy;
use App\Fraud\Models\FraudMetricSetting;
use App\Fraud\Models\UserRisk;
use App\Livewire\Admin\FraudIntelligence;
use App\Livewire\Admin\ThreatProtection;
use App\Models\User;
use App\Security\Blocklist\IpBlocklist;
use App\Security\Models\DetectorSetting;
use App\Security\Models\ThreatEvent;
use Livewire\Livewire;

test('non-admins cannot access the security views', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('admin.security.threats'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.security.fraud'))->assertForbidden();
});

test('admin can view the threat protection page', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    ThreatEvent::query()->create([
        'detector' => 'InjectionDetector',
        'threat_type' => 'sql_injection',
        'severity' => 10,
        'description' => 'SQL injection pattern detected',
        'payload' => "1' UNION SELECT",
        'ip_address' => '203.0.113.5',
        'method' => 'GET',
        'path' => '/search',
        'blocked' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.security.threats'))
        ->assertOk()
        ->assertSee('Threat Protection')
        ->assertSee('Sql Injection')
        ->assertSee('203.0.113.5');
});

test('admin can toggle a detector on and off', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ThreatProtection::class)
        ->call('toggleDetector', 'XssDetector')
        ->assertHasNoErrors();

    expect(DetectorSetting::isEnabled('XssDetector'))->toBeFalse();
});

test('admin can manually block and unblock an ip', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ThreatProtection::class)
        ->set('blockType', 'ip')
        ->set('blockValue', '198.51.100.1')
        ->call('block')
        ->assertHasNoErrors();

    expect(IpBlocklist::isBlocked('198.51.100.1'))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(ThreatProtection::class)
        ->call('unblockIp', '198.51.100.1');

    expect(IpBlocklist::isBlocked('198.51.100.1'))->toBeFalse();
});

test('admin can view the fraud intelligence page', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    UserRisk::query()->create(['user_id' => $user->id, 'score' => 85, 'level' => 'critical', 'breakdown' => []]);

    $this->actingAs($admin)
        ->get(route('admin.security.fraud'))
        ->assertOk()
        ->assertSee('Fraud Intelligence')
        ->assertSee('Scoring metrics')
        ->assertSee('Fraud level actions');
});

test('admin can update a metric weight and toggle it', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(FraudIntelligence::class)
        ->call('updateMetricWeight', 'suspicious_ip', '35')
        ->call('toggleMetric', 'same_fingerprint')
        ->assertHasNoErrors();

    expect(FraudMetricSetting::query()->where('key', 'suspicious_ip')->value('weight'))->toBe(35)
        ->and(FraudMetricSetting::query()->where('key', 'same_fingerprint')->value('enabled'))->toBe(false);
});

test('admin can change a fraud level policy', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(FraudIntelligence::class)
        ->call('updatePolicy', 'medium', 'block_fingerprint', '1')
        ->call('updatePolicy', 'high', 'user_status', 'blocked')
        ->assertHasNoErrors();

    expect(FraudLevelPolicy::forLevel('medium')->block_fingerprint)->toBeTrue()
        ->and(FraudLevelPolicy::forLevel('high')->user_status)->toBe('blocked');
});

test('admin can mark a user as false positive', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $user->forceFill(['fraud_status' => 'blocked'])->save();

    $device = Device::query()->create(['fingerprint' => 'falsepositive123']);
    $device->users()->attach($user->id);
    UserRisk::query()->create(['user_id' => $user->id, 'score' => 90, 'level' => 'critical']);

    Livewire::actingAs($admin)
        ->test(FraudIntelligence::class)
        ->call('markFalsePositive', $user->id)
        ->assertHasNoErrors();

    expect($user->fresh()->fraud_status)->toBe('active')
        ->and(UserRisk::query()->where('user_id', $user->id)->value('score'))->toBe(0);
});
