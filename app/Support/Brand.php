<?php

declare(strict_types=1);

namespace App\Support;

final class Brand
{
    public static function name(): string
    {
        return config('brand.name') ?? config('app.name');
    }

    public static function tagline(): ?string
    {
        return config('brand.tagline');
    }

    public static function logoUrl(): ?string
    {
        return config('brand.logo.url');
    }

    public static function logoAlt(): string
    {
        return config('brand.logo.alt') ?? self::name();
    }

    public static function logoWidth(): int
    {
        return (int) (config('brand.logo.width') ?? 150);
    }

    public static function logoHeight(): ?int
    {
        $height = config('brand.logo.height');

        return $height === null ? null : (int) $height;
    }

    public static function color(string $name): string
    {
        return config("brand.colors.{$name}") ?? self::defaultColor($name);
    }

    public static function font(string $type = 'body'): string
    {
        return config("brand.typography.{$type}_font") ?? config('brand.typography.body_font') ?? self::defaultFont();
    }

    public static function websiteUrl(): string
    {
        return config('brand.website_url') ?? config('app.url');
    }

    public static function supportEmail(): string
    {
        return config('brand.support_email') ?? config('mail.from.address');
    }

    private static function defaultColor(string $name): string
    {
        return match ($name) {
            'primary' => '#2563eb',
            'secondary' => '#64748b',
            'accent' => '#f59e0b',
            'background' => '#f8fafc',
            'surface' => '#ffffff',
            'text' => '#1e293b',
            'muted' => '#64748b',
            'error' => '#dc2626',
            'success' => '#16a34a',
            default => '#000000',
        };
    }

    private static function defaultFont(): string
    {
        return "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    }
}
