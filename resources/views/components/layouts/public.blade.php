@props(['title' => config('app.name')])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => config('app.appearance') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    <x-umani-analytics />
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-800 antialiased dark:bg-zinc-900 dark:text-white">
    <flux:header container class="dark border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
        <flux:brand href="{{ url('/') }}" name="{{ config('app.name') }}" wire:navigate>
            <x-slot name="logo">
                <x-lucide-box class="h-5 w-5 text-zinc-800 dark:text-white" />
            </x-slot>
        </flux:brand>

        <flux:spacer />

        @auth
            <flux:button href="{{ route('dashboard') }}" variant="ghost" wire:navigate>Dashboard</flux:button>
        @else
            <flux:button href="{{ route('signin') }}" variant="ghost" wire:navigate>Sign in</flux:button>
        @endauth
    </flux:header>

    <flux:main container class="max-w-3xl py-12">
        {{ $slot }}
    </flux:main>

    <flux:toast />
    @fluxScripts
</body>
</html>
