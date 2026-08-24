<?php

use Illuminate\Support\Facades\Config;

it('disables every supported social provider by default', function (): void {
    expect(config('authentication.social.google.enabled'))->toBeFalse()
        ->and(config('authentication.social.github.enabled'))->toBeFalse()
        ->and(config('authentication.social.apple.enabled'))->toBeFalse()
        ->and(config('authentication.social.microsoft.enabled'))->toBeFalse();
});

it('keeps captcha disabled by default and declares no active workflows', function (): void {
    expect(config('authentication.captcha.enabled'))->toBeFalse()
        ->and(config('authentication.captcha.workflows'))->toBeArray()->toBeEmpty();
});

it('does not enable a provider when its deployment flag is false', function (): void {
    Config::set('authentication.social.google.enabled', false);

    expect(config('authentication.social.google.enabled'))->toBeFalse();
});
