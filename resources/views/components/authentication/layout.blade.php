<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => config('app.appearance') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }} · {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    <x-umani-analytics />
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-800 antialiased dark:bg-zinc-900 dark:text-white">
    <div class="flex min-h-screen items-center justify-center p-6">
        <div class="w-full max-w-80 space-y-6">
            <div class="flex justify-center opacity-50">
                <a href="{{ url('/') }}" wire:navigate class="group flex items-center gap-3">
                    <x-lucide-box class="h-5 w-5 text-zinc-800 dark:text-white" />

                    <flux:text style="line-height: 1; transform: translateY(-2px)" class="text-xl font-semibold text-zinc-800 dark:text-white">{{ config('app.name') }}</flux:text>
                </a>
            </div>

            @if (! empty($title))
                <div class="space-y-2 text-center">
                    <flux:heading size="xl">{{ $title }}</flux:heading>
                    @isset($description)
                        <flux:subheading>{{ $description }}</flux:subheading>
                    @endisset
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>

    <flux:toast />
    @fluxScripts
</body>
</html>
