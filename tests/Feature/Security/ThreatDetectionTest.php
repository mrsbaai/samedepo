<?php

use App\Security\Blocklist\DeviceBlocklist;
use App\Security\Blocklist\IpBlocklist;
use App\Security\Models\DetectorSetting;
use App\Security\Models\SecurityBlock;
use App\Security\Models\ThreatEvent;

test('clean requests pass through the threat detector', function () {
    $this->get('/signin')->assertOk();
});

test('requests from a blocked ip get a plain 403', function () {
    IpBlocklist::block('192.0.2.50', 'test block');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->get('/signin')
        ->assertForbidden();
});

test('requests from an exempt local ip bypass the blocklist', function () {
    IpBlocklist::block('127.0.0.1', 'test block');

    $this->get('/signin')->assertOk();
});

test('requests from a blocked device get a plain 403', function () {
    DeviceBlocklist::block('abcdef1234567890', 'test block');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.52'])
        ->withUnencryptedCookie('device_fp', 'abcdef1234567890')
        ->get('/signin')
        ->assertForbidden();
});

test('a sql injection attempt is blocked and the ip is blocklisted', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.52'])
        ->get('/signin?q='.urlencode("1' UNION SELECT password FROM users"))
        ->assertForbidden();

    expect(IpBlocklist::isBlocked('192.0.2.52'))->toBeTrue();

    $event = ThreatEvent::query()->firstOrFail();
    expect($event->blocked)->toBeTrue()
        ->and($event->detector)->toBe('InjectionDetector')
        ->and($event->threat_type)->toBe('sql_injection');
});

test('a threat with a device fingerprint blocks the device too', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.52'])
        ->withUnencryptedCookie('device_fp', 'fingerprint1234')
        ->get('/signin?q='.urlencode('<script>alert(1)</script>'))
        ->assertForbidden();

    expect(DeviceBlocklist::isBlocked('fingerprint1234'))->toBeTrue()
        ->and(IpBlocklist::isBlocked('192.0.2.52'))->toBeTrue();
});

test('recon probes for sensitive files are blocked', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.52'])
        ->get('/.env')
        ->assertForbidden();

    expect(IpBlocklist::isBlocked('192.0.2.52'))->toBeTrue()
        ->and(ThreatEvent::query()->where('threat_type', 'sensitive_file_access')->exists())->toBeTrue();
});

test('a disabled detector no longer detects', function () {
    DetectorSetting::query()->create(['key' => 'ReconDetector', 'enabled' => false]);

    $this->get('/.env')->assertNotFound();

    expect(ThreatEvent::query()->count())->toBe(0);
});

test('the exempt health check path skips payload detection but blocked ips still 403', function () {
    $this->get('/up?q='.urlencode("1' UNION SELECT password FROM users"))->assertOk();

    IpBlocklist::block('192.0.2.51', 'test block');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.51'])
        ->get('/up')
        ->assertForbidden();
});

test('threat detection can be disabled by config', function () {
    config(['security.enabled' => false]);

    $this->get('/.env')->assertNotFound();

    expect(ThreatEvent::query()->count())->toBe(0);
});

test('an admin can unblock an ip', function () {
    IpBlocklist::block('10.0.0.9', 'test');
    IpBlocklist::unblock('10.0.0.9');

    expect(IpBlocklist::isBlocked('10.0.0.9'))->toBeFalse()
        ->and(SecurityBlock::query()->count())->toBe(0);
});
