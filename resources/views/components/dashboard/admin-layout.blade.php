@props(['title' => config('app.name')])

@php
    $user = auth()->user();
    $email = $user?->email ?? '';
    $fallbackAvatar = urlencode('https://ui-avatars.com/api/?name=' . urlencode($email) . '&size=100&background=18181B&color=f5f5f5');
    $avatarUrl = $email ? 'https://unavatar.io/' . urlencode($email) . '?fallback=' . $fallbackAvatar : null;
@endphp

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
    <flux:sidebar sticky collapsible="mobile" class="dark bg-zinc-50 border-r border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800">
        <flux:sidebar.header>
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="group flex items-center gap-3">
                <x-lucide-box class="h-5 w-5 text-white" />

                <flux:text style="line-height: 1; transform: translateY(-2px)" class="text-xl font-semibold text-white">{{ config('app.name') }}</flux:text>
            </a>

            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            <flux:sidebar.item icon="squares-2x2" href="{{ route('admin.dashboard') }}" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                Overview
            </flux:sidebar.item>

            <flux:sidebar.item icon="code-bracket" href="{{ route('admin.environment') }}" :current="request()->routeIs('admin.environment')" wire:navigate>
                Environment
            </flux:sidebar.item>

            <flux:sidebar.item icon="document-text" href="{{ route('admin.logs') }}" :current="request()->routeIs('admin.logs')" wire:navigate>
                Logs
            </flux:sidebar.item>
        </flux:sidebar.nav>
    </flux:sidebar>

    <flux:header class="dark border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
        <flux:sidebar.toggle icon="bars-2" inset="left" class="lg:hidden" aria-label="{{ __('Toggle navigation') }}" />

        <flux:spacer />

        <livewire:appearance-switcher />

        <flux:dropdown position="bottom" align="end">
            <flux:profile :avatar="$avatarUrl" />

            <flux:navmenu class="dark">
                <div class="px-2 py-1.5">
                    <flux:subheading size="sm">Signed in as</flux:subheading>
                    <flux:heading class="mt-1! truncate">{{ $email }}</flux:heading>
                </div>

                <flux:navmenu.separator />

                <flux:navmenu.item icon="home" href="{{ route('dashboard') }}" wire:navigate>Back to home</flux:navmenu.item>
                <flux:navmenu.item icon="user" href="{{ route('account') }}" wire:navigate>Account</flux:navmenu.item>

                <flux:navmenu.separator />

                <form id="signout-form" method="POST" action="{{ route('signout') }}" class="hidden">
                    @csrf
                </form>
                <flux:navmenu.item icon="arrow-right-start-on-rectangle" href="{{ route('signout') }}" onclick="event.preventDefault(); document.getElementById('signout-form').submit();">
                    Sign out
                </flux:navmenu.item>
            </flux:navmenu>
        </flux:dropdown>
    </flux:header>

    <flux:main container>
        {{ $slot }}
    </flux:main>

    <flux:toast />
    @fluxScripts
</body>
</html>
