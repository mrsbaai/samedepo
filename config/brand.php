<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brand Identity
    |--------------------------------------------------------------------------
    |
    | These values define the visual brand identity used across the
    | application, including emails, UI, and marketing materials.
    | They default to a neutral ForgeOS palette so the app looks
    | coherent before branding is finalized, and can be overridden
    | from the environment or populated by the brand-identity phase.
    |
    | Runtime fallbacks to app.name / mail.from.address / app.url are handled
    | by App\Support\Brand so this config file stays free of cross-config
    | dependencies.
    |
    */

    'name' => env('BRAND_NAME'),

    'tagline' => env('BRAND_TAGLINE'),

    'logo' => [
        'url' => env('BRAND_LOGO_URL'),
        'alt' => env('BRAND_LOGO_ALT'),
        'width' => env('BRAND_LOGO_WIDTH_PX', 150),
        'height' => env('BRAND_LOGO_HEIGHT_PX'),
    ],

    'colors' => [
        'primary' => env('BRAND_COLOR_PRIMARY', '#2563eb'),
        'secondary' => env('BRAND_COLOR_SECONDARY', '#64748b'),
        'accent' => env('BRAND_COLOR_ACCENT', '#f59e0b'),
        'background' => env('BRAND_COLOR_BACKGROUND', '#f8fafc'),
        'surface' => env('BRAND_COLOR_SURFACE', '#ffffff'),
        'text' => env('BRAND_COLOR_TEXT', '#1e293b'),
        'muted' => env('BRAND_COLOR_MUTED', '#64748b'),
        'error' => env('BRAND_COLOR_ERROR', '#dc2626'),
        'success' => env('BRAND_COLOR_SUCCESS', '#16a34a'),
    ],

    'typography' => [
        'heading_font' => env('BRAND_FONT_HEADING', "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"),
        'body_font' => env('BRAND_FONT_BODY', "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"),
    ],

    'support_email' => env('BRAND_SUPPORT_EMAIL'),

    'website_url' => env('BRAND_WEBSITE_URL'),

];
