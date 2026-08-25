@props(['title' => 'samedepo', 'description' => 'Permanent crypto deposit addresses and automatic top-up tracking for website owners.'])

<!DOCTYPE html>
{{-- samedepo public pages are always dark — a deliberate brand decision. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }} — samedepo</title>
        <meta name="description" content="{{ $description }}">

        @fluxAppearance

        {{-- Fonts --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=geist:400,500,600,700|geist-mono:400,500|space-grotesk:500,600,700" rel="stylesheet" />

        <meta name="color-scheme" content="dark">

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('favicon-180x180.png') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('favicon-192x192.png') }}">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <x-umani-analytics />
    </head>
    <body class="min-h-screen bg-zinc-900 text-zinc-100 antialiased">
        <div>
            <flux:header container class="border-b border-zinc-700 bg-zinc-900/80 backdrop-blur">
                <flux:brand href="{{ url('/') }}" name="{{ config('app.name') }}" wire:navigate>
                    <x-slot name="logo" class="text-(--color-accent)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true">
                            <path d="M12 8v8"/>
                            <path d="M2.7 10.3a2.41 2.41 0 0 0 0 3.41l7.59 7.59a2.41 2.41 0 0 0 3.41 0l7.59-7.59a2.41 2.41 0 0 0 0-3.41L13.7 2.71a2.41 2.41 0 0 0-3.41 0z"/>
                            <path d="M8 12h8"/>
                        </svg>
                    </x-slot>
                </flux:brand>

                <flux:separator vertical variant="subtle" class="mx-2 h-4 max-lg:hidden" />

                <flux:navbar class="max-lg:hidden">
                    <flux:navbar.item href="{{ route('public.api-docs') }}" class="text-zinc-400!" wire:navigate>API Docs</flux:navbar.item>
                </flux:navbar>

                <flux:spacer />

                <div class="flex items-center gap-2">
                    @auth
                        <flux:button href="{{ route('dashboard') }}" variant="ghost" size="sm" wire:navigate>Dashboard</flux:button>
                    @else
                        <flux:button href="{{ route('signin') }}" variant="ghost" size="sm" wire:navigate>Sign in</flux:button>
                        <flux:button href="{{ route('signup') }}" variant="primary" size="sm" wire:navigate>Sign up</flux:button>
                    @endauth
                </div>
            </flux:header>

            <flux:main container class="py-0">
                {{ $slot }}
            </flux:main>
        </div>

        <footer class="border-t border-zinc-700 mt-24">
            <div class="mx-auto max-w-6xl px-6 py-10 flex flex-col sm:flex-row items-center justify-between gap-4">
                <flux:text size="sm" variant="subtle">&copy; {{ date('Y') }} samedepo. All rights reserved.</flux:text>
                <flux:navbar class="gap-1">
                    <flux:navbar.item href="{{ route('public.api-docs') }}" wire:navigate>API Docs</flux:navbar.item>
                </flux:navbar>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
