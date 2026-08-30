@props(['title' => 'samedepo', 'description' => 'Permanent crypto deposit addresses and automatic top-up tracking for website owners.'])

@php
    $user = auth()->user();
    $isAdmin = $user?->is_admin ?? false;
    $homeRoute = $isAdmin ? route('admin.dashboard') : route('dashboard');
    $email = $user?->email ?? '';
    $fallbackAvatar = urlencode('https://ui-avatars.com/api/?name=' . urlencode($email) . '&size=100&background=18181B&color=f5f5f5');
    $avatarUrl = $email ? 'https://unavatar.io/' . urlencode($email) . '?fallback=' . $fallbackAvatar : null;

    $userTickets = (! $isAdmin && $user) ? $user->supportTickets()->get() : collect();
    $openTicket = $userTickets->firstWhere('status', \App\Models\SupportTicket::STATUS_OPEN);
    $unreadSupportCount = $userTickets->sum(fn ($ticket) => $ticket->unreadCountFor($user));
    $supportHref = $openTicket ? route('support', ['tab' => 'tickets']) : route('support');
@endphp

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

        <x-favicon />

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <x-umani-analytics />
    </head>
    <body class="min-h-screen bg-zinc-900 text-zinc-100 antialiased">
        <div>
            <flux:header container class="bg-zinc-900/80 backdrop-blur-xl">
                <x-brand :href="url('/')" />

                <flux:navbar class="ml-6 max-lg:hidden">
                    <flux:navbar.item href="{{ url('/#how-it-works') }}">How it works</flux:navbar.item>
                    <flux:navbar.item href="{{ route('public.api-docs') }}" wire:navigate>API Docs</flux:navbar.item>
                </flux:navbar>

                <flux:spacer />

                <div class="flex items-center gap-2">
                    @auth
                        <flux:button href="{{ $homeRoute }}" variant="ghost" size="sm" wire:navigate>
                            {{ $isAdmin ? 'Overview' : 'Dashboard' }}
                        </flux:button>

                        <flux:dropdown position="bottom" align="end">
                            @if ($isAdmin)
                                <livewire:admin.profile-avatar />
                            @else
                                <flux:profile :avatar="$avatarUrl" />
                            @endif

                            <flux:navmenu class="dark">
                                <div class="px-2 py-1.5">
                                    <flux:subheading size="sm">Signed in as</flux:subheading>
                                    <flux:heading class="mt-1! truncate">{{ $email }}</flux:heading>
                                </div>

                                <flux:navmenu.separator />

                                <flux:navmenu.item icon="shield-check" href="{{ route('security') }}" wire:navigate>Security</flux:navmenu.item>
                                @unless ($isAdmin)
                                    <flux:navmenu.item icon="question-mark-circle" href="{{ $supportHref }}" wire:navigate>
                                        Support
                                        @if ($unreadSupportCount > 0)
                                            <flux:badge size="sm" color="amber" class="ml-1">{{ $unreadSupportCount }}</flux:badge>
                                        @endif
                                    </flux:navmenu.item>
                                @endunless

                                <flux:navmenu.separator />

                                <form id="public-signout-form" method="POST" action="{{ route('signout') }}" class="hidden">
                                    @csrf
                                </form>
                                <flux:navmenu.item icon="arrow-right-start-on-rectangle" href="{{ route('signout') }}" onclick="event.preventDefault(); document.getElementById('public-signout-form').submit();">
                                    Sign out
                                </flux:navmenu.item>
                            </flux:navmenu>
                        </flux:dropdown>
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
                    @guest
                        <flux:navbar.item href="{{ route('privacy') }}" wire:navigate>Privacy</flux:navbar.item>
                        <flux:navbar.item href="{{ route('terms') }}" wire:navigate>Terms</flux:navbar.item>
                    @endguest
                </flux:navbar>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
