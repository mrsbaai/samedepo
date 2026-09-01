<?php

use App\Support\Brand;

it('returns the configured brand name', function (): void {
    config(['brand.name' => 'Acme SaaS']);

    expect(Brand::name())->toBe('Acme SaaS');
});

it('falls back to the app name when brand name is not configured', function (): void {
    config(['brand.name' => null]);
    config(['app.name' => 'ForgeOS']);

    expect(Brand::name())->toBe('ForgeOS');
});

it('returns brand colors with sensible defaults', function (): void {
    config([
        'brand.colors.primary' => '#ff5733',
        'brand.colors.text' => null,
    ]);

    expect(Brand::color('primary'))->toBe('#ff5733')
        ->and(Brand::color('text'))->toBe('#1e293b')
        ->and(Brand::color('unknown'))->toBe('#000000');
});

it('returns the brand logo url and alt text', function (): void {
    config(['brand.logo.url' => 'https://example.com/logo.png']);
    config(['brand.logo.alt' => 'Acme Logo']);

    expect(Brand::logoUrl())->toBe('https://example.com/logo.png')
        ->and(Brand::logoAlt())->toBe('Acme Logo');
});

it('falls back to the brand name for logo alt text', function (): void {
    config(['brand.logo.url' => 'https://example.com/logo.png']);
    config(['brand.logo.alt' => null]);
    config(['brand.name' => 'Acme SaaS']);

    expect(Brand::logoAlt())->toBe('Acme SaaS');
});

it('returns configured typography fonts', function (): void {
    config(['brand.typography.heading_font' => 'Georgia, serif']);
    config(['brand.typography.body_font' => 'Arial, sans-serif']);

    expect(Brand::font('heading'))->toBe('Georgia, serif')
        ->and(Brand::font('body'))->toBe('Arial, sans-serif');
});

it('falls back to the body font for unknown typography types', function (): void {
    config(['brand.typography.body_font' => 'Arial, sans-serif']);
    config(['brand.typography.heading_font' => null]);

    expect(Brand::font('heading'))->toBe('Arial, sans-serif');
});

it('returns the configured website and support urls', function (): void {
    config(['brand.website_url' => 'https://acme.test']);
    config(['brand.support_email' => 'help@acme.test']);

    expect(Brand::websiteUrl())->toBe('https://acme.test')
        ->and(Brand::supportEmail())->toBe('help@acme.test');
});
