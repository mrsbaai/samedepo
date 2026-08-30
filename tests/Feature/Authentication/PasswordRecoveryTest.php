<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\ForgotPassword;
use App\Livewire\Authentication\ResetPassword;
use App\Livewire\Authentication\VerifyOtp;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Notifications\Authentication\PasswordRecoveryCodeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

it('acknowledges recovery requests identically and redirects to OTP verification', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'taylor@example.test']);

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('sendCode')
        ->assertRedirect(route('password.otp', ['email' => $user->email]));

    RateLimiter::clear('password-recovery|unknown@example.test|127.0.0.1');
    Livewire::test(ForgotPassword::class)
        ->set('email', 'unknown@example.test')
        ->call('sendCode')
        ->assertRedirect(route('password.otp', ['email' => 'unknown@example.test']));

    expect(OtpChallenge::query()->where('email', $user->email)->count())->toBe(1)
        ->and(OtpChallenge::query()->where('email', 'unknown@example.test')->exists())->toBeFalse();

    Notification::assertSentTo($user, PasswordRecoveryCodeNotification::class);
});

it('stores only a hashed, expiring OTP challenge', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('sendCode');

    $challenge = OtpChallenge::query()->sole();

    expect($challenge->code)->not->toBe('123456')
        ->and(strlen($challenge->code))->toBeGreaterThan(20)
        ->and($challenge->expires_at->isAfter(now()))->toBeTrue()
        ->and($challenge->purpose)->toBe('password_recovery');
});

it('verifies a valid OTP once and opens the reset flow', function (): void {
    $user = User::factory()->create();
    $challenge = OtpChallenge::query()->create([
        'user_id' => $user->id,
        'email' => $user->email,
        'purpose' => 'password_recovery',
        'code' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(10),
    ]);

    Livewire::withQueryParams(['email' => $user->email])->test(VerifyOtp::class)
        ->set('code', '123456')
        ->call('verify')
        ->assertRedirect(route('password.reset'));

    expect(session('password_recovery.challenge_id'))->toBe($challenge->id);
});

it('rejects expired, consumed, and exhausted OTP challenges', function (): void {
    $user = User::factory()->create();

    foreach ([
        ['expires_at' => now()->subMinute()],
        ['consumed_at' => now()],
        ['attempts' => (int) config('authentication.otp.maximum_attempts')],
    ] as $state) {
        OtpChallenge::query()->create(array_merge([
            'user_id' => $user->id,
            'email' => $user->email,
            'purpose' => 'password_recovery',
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ], $state));

        Livewire::withQueryParams(['email' => $user->email])->test(VerifyOtp::class)
            ->set('code', '123456')
            ->call('verify')
            ->assertSet('error', 'This code is invalid or has expired.');

        OtpChallenge::query()->delete();
    }
});

it('enforces OTP verification throttling by normalized email and IP', function (): void {
    $email = 'taylor@example.test';
    $key = 'otp-verification|'.$email.'|127.0.0.1';
    RateLimiter::clear($key);

    foreach (range(1, (int) config('authentication.rate_limits.otp_verification')) as $attempt) {
        Livewire::withQueryParams(['email' => $email])->test(VerifyOtp::class)
            ->set('code', '000000')
            ->call('verify');
    }

    Livewire::withQueryParams(['email' => $email])->test(VerifyOtp::class)
        ->set('code', '000000')
        ->call('verify')
        ->assertSet('error', 'Too many verification attempts. Please try again later.');
});

it('limits OTP resends by normalized email and IP', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'taylor@example.test']);
    $key = 'otp-resend|'.$user->email.'|127.0.0.1';
    RateLimiter::clear($key);

    foreach (range(1, (int) config('authentication.otp.maximum_resends')) as $attempt) {
        Livewire::withQueryParams(['email' => strtoupper($user->email)])->test(VerifyOtp::class)
            ->call('resend')
            ->assertSet('status', 'If an account exists, we sent a new recovery code.');
    }

    Livewire::withQueryParams(['email' => $user->email])->test(VerifyOtp::class)
        ->call('resend')
        ->assertSet('status', 'Please wait before requesting another recovery code.');

    Notification::assertSentToTimes($user, PasswordRecoveryCodeNotification::class, (int) config('authentication.otp.maximum_resends'));
});

it('resets the password, consumes the OTP, and records a security event', function (): void {
    Event::fake([AuthenticationEvent::class]);
    $user = User::factory()->create(['password' => 'VioletRidge4829']);
    $challenge = OtpChallenge::query()->create([
        'user_id' => $user->id,
        'email' => $user->email,
        'purpose' => 'password_recovery',
        'code' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(10),
        'consumed_at' => now(),
    ]);
    DB::table('sessions')->insert([
        'id' => 'recovered-user-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    session([
        'password_recovery.challenge_id' => $challenge->id,
        'password_recovery.email' => $user->email,
    ]);

    Livewire::test(ResetPassword::class)
        ->set('password', 'WinterHarbor4829')
        ->set('passwordConfirmation', 'WinterHarbor4829')
        ->call('resetPassword')
        ->assertRedirect(route('signin'));

    expect(Hash::check('WinterHarbor4829', $user->fresh()->password))->toBeTrue()
        ->and($challenge->fresh()->consumed_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::PASSWORD_CHANGED && $event->user?->is($user));
});

it('renders the Flux recovery controls and approved OTP primitive', function (): void {
    Livewire::test(ForgotPassword::class)
        ->assertSee('Email')
        ->assertSee('Send recovery code')
        ->assertSeeHtml('wire:loading');

    Livewire::withQueryParams(['email' => 'taylor@example.test'])->test(VerifyOtp::class)
        ->assertSee('Enter the 6-digit code')
        ->assertSeeHtml('inputmode="numeric"');
});

it('includes a direct link to the OTP verification page in the recovery email', function (): void {
    $user = User::factory()->create(['email' => 'recovery-link@example.test']);
    $notification = new PasswordRecoveryCodeNotification('123456');
    $mail = $notification->toMail($user);

    expect($mail->actionUrl)->toBe(route('password.otp', ['email' => $user->email]))
        ->and($mail->actionText)->toBe('Enter recovery code');
});
