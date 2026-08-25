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
            <div class="flex justify-center">
                <flux:brand href="{{ url('/') }}" name="{{ config('app.name') }}" wire:navigate>
                    <x-slot name="logo" class="text-(--color-accent)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true">
                            <path d="M12 8v8"/>
                            <path d="M2.7 10.3a2.41 2.41 0 0 0 0 3.41l7.59 7.59a2.41 2.41 0 0 0 3.41 0l7.59-7.59a2.41 2.41 0 0 0 0-3.41L13.7 2.71a2.41 2.41 0 0 0-3.41 0z"/>
                            <path d="M8 12h8"/>
                        </svg>
                    </x-slot>
                </flux:brand>
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
