<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;

it('defines the authentication security policy defaults', function (): void {
    expect(config('authentication.password.minimum_length'))->toBe(8)
        ->and(config('authentication.password.require_mixed_case'))->toBeTrue()
        ->and(config('authentication.password.require_numbers'))->toBeTrue()
        ->and(config('authentication.password.uncompromised'))->toBeTrue()
        ->and(config('authentication.otp.length'))->toBe(6)
        ->and(config('authentication.otp.expires_after_minutes'))->toBeGreaterThan(0)
        ->and(config('authentication.two_factor.enabled'))->toBeTrue()
        ->and(config('authentication.security_notifications.new_device_signin'))->toBeTrue();
});

it('defines every sensitive workflow rate limit', function (): void {
    expect(array_keys(config('authentication.rate_limits')))->toEqualCanonicalizing([
        'signin',
        'signup',
        'password_recovery',
        'otp_verification',
        'otp_resend',
        'verification_resend',
        'two_factor',
    ]);
});

it('builds sign-in throttles from normalized email and ip address', function (): void {
    $request = Request::create('/signin', 'POST', [
        'email' => 'USER@Example.test',
    ], server: [
        'REMOTE_ADDR' => '203.0.113.10',
    ]);

    $limiter = RateLimiter::limiter('signin');
    $limit = $limiter($request);

    expect($limit->key)->toContain('user@example.test')
        ->and($limit->key)->toContain('203.0.113.10');
});

it('never registers Fortify\'s own routes in favor of the project sign-in and sign-up pages', function (): void {
    expect(Fortify::$registersRoutes)->toBeFalse();

    foreach (['login', 'register', 'password.confirm'] as $name) {
        expect(Route::has($name))->toBeFalse();
    }

    expect(Route::has('signin'))->toBeTrue()
        ->and(Route::has('signup'))->toBeTrue();
});
