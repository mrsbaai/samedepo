<?php

use App\Support\Brand;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\BrandTestMail;

beforeEach(function (): void {
    config([
        'brand.name' => 'Acme SaaS',
        'brand.logo.url' => 'https://acme.test/logo.png',
        'brand.colors.primary' => '#ff5733',
        'brand.colors.success' => '#0f9d58',
        'brand.colors.background' => '#f0f0f0',
        'brand.colors.surface' => '#ffffff',
        'brand.colors.text' => '#111111',
        'brand.colors.muted' => '#666666',
        'brand.typography.heading_font' => 'Georgia, serif',
        'brand.typography.body_font' => 'Arial, sans-serif',
        'brand.website_url' => 'https://acme.test',
    ]);
});

it('renders the brand name in the email header and footer', function (): void {
    Mail::fake();

    Mail::to('user@example.test')->send(new BrandTestMail('Hello from the brand.'));

    Mail::assertSent(BrandTestMail::class, function (BrandTestMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'Acme SaaS')
            && str_contains($html, 'https://acme.test')
            && str_contains($html, 'Hello from the brand.');
    });
});

it('renders the brand logo when configured', function (): void {
    Mail::fake();

    Mail::to('user@example.test')->send(new BrandTestMail('Logo test.'));

    Mail::assertSent(BrandTestMail::class, function (BrandTestMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'https://acme.test/logo.png')
            && str_contains($html, 'alt="Acme SaaS"')
            && ! str_contains($html, '<a href="https://acme.test"')
                || str_contains($html, '<a href="https://acme.test"');
    });
});

it('applies brand colors to the email layout', function (): void {
    Mail::fake();

    Mail::to('user@example.test')->send(new BrandTestMail('Color test.'));

    Mail::assertSent(BrandTestMail::class, function (BrandTestMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'background-color: #f0f0f0')
            && str_contains($html, 'background-color: #ffffff')
            && str_contains($html, 'color: #111111')
            && str_contains($html, 'color: #666666');
    });
});

it('applies brand colors to panels and subcopy', function (): void {
    Mail::fake();

    Mail::to('user@example.test')->send(new BrandTestMail('Panel test.'));

    Mail::assertSent(BrandTestMail::class, function (BrandTestMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'border-left: 4px solid #64748b')
            && str_contains($html, 'background-color: #f0f0f0')
            && str_contains($html, 'border-top: 1px solid #f0f0f0')
            && str_contains($html, 'This is a subcopy section.');
    });
});

it('uses brand colors for primary and success buttons', function (): void {
    Mail::fake();

    Mail::to('user@example.test')->send(new BrandTestMail('Button test.'));

    Mail::assertSent(BrandTestMail::class, function (BrandTestMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'background-color: #ff5733')
            && str_contains($html, 'background-color: #0f9d58');
    });
});

it('uses brand fonts in the email styles', function (): void {
    Mail::fake();

    Mail::to('user@example.test')->send(new BrandTestMail('Font test.'));

    Mail::assertSent(BrandTestMail::class, function (BrandTestMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'font-family: Georgia, serif')
            && str_contains($html, 'font-family: Arial, sans-serif');
    });
});

it('falls back to app name when brand logo is not configured', function (): void {
    config(['brand.logo.url' => null]);
    config(['app.name' => 'ForgeOS Default']);

    Mail::fake();

    Mail::to('user@example.test')->send(new BrandTestMail('Fallback test.'));

    Mail::assertSent(BrandTestMail::class, function (BrandTestMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'ForgeOS Default')
            && ! str_contains($html, 'https://acme.test/logo.png');
    });
});

it('uses the default ForgeOS palette when brand config is empty', function (): void {
    config(['brand' => []]);

    Mail::fake();

    Mail::to('user@example.test')->send(new BrandTestMail('Default palette test.'));

    Mail::assertSent(BrandTestMail::class, function (BrandTestMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, Brand::color('primary'))
            && str_contains($html, Brand::color('background'))
            && str_contains($html, Brand::color('surface'));
    });
});
