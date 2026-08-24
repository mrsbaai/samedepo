<?php

use App\Events\Authentication\AuthenticationEvent;
use App\Livewire\Authentication\Signin;
use App\Livewire\Authentication\Signup;
use App\Models\AuthenticationIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;

function enableGoogleConfig(): void
{
    config([
        'authentication.social.google.enabled' => true,
        'services.google' => [
            'client_id' => 'google-client-id',
            'client_secret' => 'google-client-secret',
            'redirect' => 'http://localhost/social/google/callback',
        ],
    ]);
}

function fakeGoogleSocialiteUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake(array_merge([
        'id' => 'google-provider-id',
        'name' => 'Google User',
        'email' => 'google@example.com',
    ], $attributes));
}

function mockSocialiteProvider(string $provider): MockInterface
{
    $socialiteProvider = Mockery::mock(SocialiteProvider::class);

    Socialite::shouldReceive('driver')
        ->with($provider)
        ->andReturn($socialiteProvider);

    return $socialiteProvider;
}

it('hides google sign-in while disabled and rejects social routes', function (): void {
    Livewire\Livewire::test(Signin::class)
        ->assertDontSee('Continue with Google');

    Livewire\Livewire::test(Signup::class)
        ->assertDontSee('Continue with Google');

    $this->get(route('social.redirect', 'google'))
        ->assertNotFound();

    $this->get(route('social.callback', 'google'))
        ->assertNotFound();
});

it('shows google sign-in button when enabled', function (): void {
    enableGoogleConfig();

    Livewire\Livewire::test(Signin::class)
        ->assertSee('Continue with Google')
        ->assertSee(route('social.redirect', 'google'));

    Livewire\Livewire::test(Signup::class)
        ->assertSee('Continue with Google');
});

it('redirects to google when enabled', function (): void {
    enableGoogleConfig();

    $provider = mockSocialiteProvider('google');
    $provider->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect()->away('https://accounts.google.com/oauth/authorize'));

    $this->get(route('social.redirect', 'google'))
        ->assertRedirect('https://accounts.google.com/oauth/authorize');
});

it('creates a new user and logs them in through google callback', function (): void {
    enableGoogleConfig();
    Event::fake([AuthenticationEvent::class]);

    $provider = mockSocialiteProvider('google');
    $provider->shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleSocialiteUser());

    $this->get(route('social.callback', 'google'))
        ->assertRedirect('/');

    $user = User::query()->where('email', 'google@example.com')->sole();

    expect(auth()->id())->toBe($user->id)
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $identity = AuthenticationIdentity::query()->whereBelongsTo($user)->sole();

    expect($identity->provider)->toBe('google')
        ->and($identity->provider_user_hash)->toBe(hash('sha256', 'google-provider-id'));

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::USER_SIGNED_UP && $event->user?->is($user));
    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::USER_SIGNED_IN && $event->user?->is($user));
});

it('logs in an existing user whose google identity is already linked', function (): void {
    enableGoogleConfig();
    Event::fake([AuthenticationEvent::class]);

    $user = User::factory()->create([
        'email' => 'google@example.com',
        'is_active' => true,
    ]);

    $user->authenticationIdentities()->create([
        'provider' => 'google',
        'provider_user_hash' => hash('sha256', 'google-provider-id'),
        'provider_user_id' => 'google-provider-id',
    ]);

    $provider = mockSocialiteProvider('google');
    $provider->shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleSocialiteUser());

    $this->get(route('social.callback', 'google'))
        ->assertRedirect('/');

    expect(auth()->id())->toBe($user->id);

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::USER_SIGNED_IN && $event->user?->is($user));
});

it('challenges two-factor authentication for an existing linked user with 2fa enabled', function (): void {
    enableGoogleConfig();

    $user = User::factory()->create([
        'email' => 'google@example.com',
        'is_active' => true,
    ]);

    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $user->authenticationIdentities()->create([
        'provider' => 'google',
        'provider_user_hash' => hash('sha256', 'google-provider-id'),
        'provider_user_id' => 'google-provider-id',
    ]);

    $provider = mockSocialiteProvider('google');
    $provider->shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleSocialiteUser());

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('two-factor.challenge'));

    expect(auth()->check())->toBeFalse()
        ->and(session('signin.id'))->toBe($user->id);
});

it('returns a conflict when the google email already belongs to an unlinked account', function (): void {
    enableGoogleConfig();

    User::factory()->create([
        'email' => 'google@example.com',
        'is_active' => true,
    ]);

    $provider = mockSocialiteProvider('google');
    $provider->shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleSocialiteUser());

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('signin'))
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('links a google account for an authenticated user', function (): void {
    enableGoogleConfig();
    Event::fake([AuthenticationEvent::class]);

    $user = User::factory()->create([
        'email' => 'existing@example.com',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $provider = mockSocialiteProvider('google');
    $provider->shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleSocialiteUser());

    $this->get(route('social.callback', 'google'))
        ->assertRedirect('/');

    $identity = $user->authenticationIdentities()->sole();

    expect($identity->provider)->toBe('google')
        ->and($identity->provider_user_hash)->toBe(hash('sha256', 'google-provider-id'));

    Event::assertDispatched(AuthenticationEvent::class, fn (AuthenticationEvent $event): bool => $event->type === AuthenticationEvent::SOCIAL_PROVIDER_LINKED && $event->user?->is($user));
});

it('prevents linking a google account already tied to another user', function (): void {
    enableGoogleConfig();

    $otherUser = User::factory()->create(['is_active' => true]);
    $currentUser = User::factory()->create(['is_active' => true]);

    $otherUser->authenticationIdentities()->create([
        'provider' => 'google',
        'provider_user_hash' => hash('sha256', 'google-provider-id'),
        'provider_user_id' => 'google-provider-id',
    ]);

    $this->actingAs($currentUser);

    $provider = mockSocialiteProvider('google');
    $provider->shouldReceive('user')
        ->once()
        ->andReturn(fakeGoogleSocialiteUser());

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('signin'))
        ->assertSessionHasErrors('email');
});
