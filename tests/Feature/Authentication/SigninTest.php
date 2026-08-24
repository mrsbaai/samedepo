<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\Signin;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

it('validates the sign-in credentials', function (): void {
    Livewire::test(Signin::class)
        ->set('email', 'not-an-email')
        ->set('password', '')
        ->call('signin')
        ->assertHasErrors([
            'email' => 'email',
            'password' => 'required',
        ]);
});

it('logs in an active verified account with normalized email and remember-me support', function (): void {
    Notification::fake();
    $user = User::factory()->create([
        'email' => 'taylor@example.test',
        'password' => 'VioletRidge4829',
        'is_active' => true,
    ]);

    Livewire::test(Signin::class)
        ->set('email', '  TAYLOR@EXAMPLE.TEST ')
        ->set('password', 'VioletRidge4829')
        ->set('remember', true)
        ->call('signin')
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);

    expect(SecurityEvent::query()->whereBelongsTo($user)->where('event_type', AuthenticationEvent::USER_SIGNED_IN)->exists())->toBeTrue();
    Notification::assertSentTo($user, SecurityAlertNotification::class);
});

it('does not authenticate unverified or inactive accounts and returns generic feedback', function (array $attributes): void {
    $user = User::factory()->create(array_merge([
        'password' => 'VioletRidge4829',
    ], $attributes));

    Livewire::test(Signin::class)
        ->set('email', $user->email)
        ->set('password', 'VioletRidge4829')
        ->call('signin')
        ->assertSet('error', 'These credentials do not match our records.');

    expect(auth()->check())->toBeFalse();
})->with([
    'unverified' => [['email_verified_at' => null]],
    'inactive' => [['is_active' => false]],
]);

it('records a generic failed-sign-in event without authenticating invalid credentials', function (): void {
    Event::fake([AuthenticationEvent::class]);
    $user = User::factory()->create(['password' => 'VioletRidge4829']);

    Livewire::test(Signin::class)
        ->set('email', $user->email)
        ->set('password', 'incorrect-password')
        ->call('signin')
        ->assertSet('error', 'These credentials do not match our records.');

    expect(auth()->check())->toBeFalse();
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::SIGNIN_FAILED && $event->user === null);
});

it('throttles repeated sign-in attempts by normalized email and IP', function (): void {
    $email = 'taylor@example.test';
    $key = 'signin|'.$email.'|127.0.0.1';
    RateLimiter::clear($key);

    foreach (range(1, (int) config('authentication.rate_limits.signin')) as $attempt) {
        Livewire::test(Signin::class)
            ->set('email', 'TAYLOR@EXAMPLE.TEST')
            ->set('password', 'incorrect-password')
            ->call('signin');
    }

    Livewire::test(Signin::class)
        ->set('email', $email)
        ->set('password', 'incorrect-password')
        ->call('signin')
        ->assertSet('error', 'Too many sign-in attempts. Please try again later.');
});

it('renders Flux sign-in controls and state feedback', function (): void {
    Livewire::test(Signin::class)
        ->assertSee('Email')
        ->assertSee('Password')
        ->assertSee('Forgot password?')
        ->assertSee('Sign in')
        ->assertSeeHtml('wire:loading');
});

it('redirects an already authenticated user away from the sign-in and sign-up pages', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('signin'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('signup'))
        ->assertRedirect(route('dashboard'));
});

it('redirects an already authenticated admin away from the sign-in page to the admin dashboard', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('signin'))
        ->assertRedirect(route('admin.dashboard'));
});
