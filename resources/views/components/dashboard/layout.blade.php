@props(['title' => config('app.name')])

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

    $ownerNav = config('fluxos-nav.owner', ['main' => [], 'settings' => []]);
    $ownerNavHref = fn (array $item) => \Illuminate\Support\Facades\Route::has($item['route']) ? route($item['route']) : url($item['path']);
    $ownerNavCurrent = fn (array $item) => request()->is(ltrim($item['path'] . '*', '/'));

    $adminHref = fn (string $route, string $path) => \Illuminate\Support\Facades\Route::has($route) ? route($route) : url($path);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => config('app.appearance') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <x-favicon />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    <x-umani-analytics />
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-800 antialiased dark:bg-zinc-900 dark:text-white">
    <flux:header sticky container class="dark border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 flex items-center">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" aria-label="{{ __('Toggle navigation') }}" />

        <x-brand :href="$homeRoute" variant="authenticated" class="max-lg:hidden" />

        <flux:navbar class="-mb-px max-lg:hidden ml-6">
            @if ($isAdmin)
                <flux:navbar.item href="{{ route('admin.dashboard') }}" :current="request()->routeIs('admin.dashboard')">
                    Overview
                </flux:navbar.item>

                <flux:dropdown>
                    <flux:navbar.item icon:trailing="chevron-down" :current="request()->routeIs('admin.tickets*') || request()->routeIs('admin.support.settings') || request()->routeIs('admin.faqs')">Support</flux:navbar.item>
                    <flux:navmenu class="dark">
                        <flux:navmenu.item href="{{ route('admin.tickets') }}" :current="request()->routeIs('admin.tickets*')" wire:navigate>Tickets</flux:navmenu.item>
                        <flux:navmenu.item href="{{ route('admin.support.settings') }}" :current="request()->routeIs('admin.support.settings')" wire:navigate>Support Settings</flux:navmenu.item>
                        <flux:navmenu.item href="{{ route('admin.faqs') }}" :current="request()->routeIs('admin.faqs')" wire:navigate>FAQs</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>

                <flux:navbar.item href="{{ route('admin.users') }}" :current="request()->routeIs('admin.users*')">
                    Users
                </flux:navbar.item>

                <flux:dropdown>
                    <flux:navbar.item icon:trailing="chevron-down" :current="request()->routeIs('admin.announcement') || request()->routeIs('admin.legal.edit')">Content</flux:navbar.item>
                    <flux:navmenu class="dark">
                        <flux:navmenu.item href="{{ route('admin.announcement') }}" :current="request()->routeIs('admin.announcement')" wire:navigate>Announcement</flux:navmenu.item>
                        <flux:navmenu.item href="{{ route('admin.legal.edit', 'privacy') }}" :current="request()->is('admin/legal/privacy')" wire:navigate>Privacy Policy</flux:navmenu.item>
                        <flux:navmenu.item href="{{ route('admin.legal.edit', 'terms') }}" :current="request()->is('admin/legal/terms')" wire:navigate>Terms of Service</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>

                <flux:dropdown>
                    <flux:navbar.item icon:trailing="chevron-down" :current="request()->routeIs('admin.environment') || request()->routeIs('admin.logs')">System</flux:navbar.item>
                    <flux:navmenu class="dark">
                        <flux:navmenu.item href="{{ route('admin.environment') }}" :current="request()->routeIs('admin.environment')" wire:navigate>Environment</flux:navmenu.item>
                        <flux:navmenu.item href="{{ route('admin.logs') }}" :current="request()->routeIs('admin.logs')" wire:navigate>Logs</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>

                <flux:dropdown>
                    <flux:navbar.item icon:trailing="chevron-down" :current="request()->routeIs('admin.security.*')">Security</flux:navbar.item>
                    <flux:navmenu class="dark">
                        <flux:navmenu.item href="{{ route('admin.security.threats') }}" :current="request()->routeIs('admin.security.threats')" wire:navigate>Threats</flux:navmenu.item>
                        <flux:navmenu.item href="{{ route('admin.security.fraud') }}" :current="request()->routeIs('admin.security.fraud')" wire:navigate>Fraud</flux:navmenu.item>
                        <flux:navmenu.item href="{{ route('admin.security.forbidden-log') }}" :current="request()->routeIs('admin.security.forbidden-log')" wire:navigate>Forbidden Log</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>

                <flux:dropdown>
                    <flux:navbar.item icon:trailing="chevron-down" :current="request()->is('admin/owners*') || request()->is('admin/withdrawals*') || request()->is('admin/treasury')">Finance</flux:navbar.item>
                    <flux:navmenu class="dark">
                        <flux:navmenu.item href="{{ $adminHref('admin.owners', '/admin/owners') }}" :current="request()->is('admin/owners*')" wire:navigate>Website Owners</flux:navmenu.item>
                        <flux:navmenu.item href="{{ $adminHref('admin.withdrawals', '/admin/withdrawals') }}" :current="request()->is('admin/withdrawals*')" wire:navigate>Withdrawal Queue</flux:navmenu.item>
                        <flux:navmenu.item href="{{ $adminHref('admin.treasury', '/admin/treasury') }}" :current="request()->is('admin/treasury')" wire:navigate>Treasury</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>

                <flux:dropdown>
                    <flux:navbar.item icon:trailing="chevron-down" :current="request()->is('admin/platform-settings') || request()->is('admin/withdrawal-settings')">Platform</flux:navbar.item>
                    <flux:navmenu class="dark">
                        <flux:navmenu.item href="{{ $adminHref('admin.platform-settings', '/admin/platform-settings') }}" :current="request()->is('admin/platform-settings')" wire:navigate>Platform Settings</flux:navmenu.item>
                        <flux:navmenu.item href="{{ $adminHref('admin.withdrawal-settings', '/admin/withdrawal-settings') }}" :current="request()->is('admin/withdrawal-settings')" wire:navigate>Withdrawal Settings</flux:navmenu.item>
                    </flux:navmenu>
                </flux:dropdown>
            @else
                @foreach ($ownerNav['main'] as $item)
                    <flux:navbar.item href="{{ $ownerNavHref($item) }}" :current="$ownerNavCurrent($item)" wire:navigate>
                        {{ $item['label'] }}
                    </flux:navbar.item>
                @endforeach

                <flux:dropdown>
                    <flux:navbar.item icon:trailing="chevron-down" :current="collect($ownerNav['settings'])->contains(fn (array $item) => $ownerNavCurrent($item))">Settings</flux:navbar.item>
                    <flux:navmenu class="dark">
                        @foreach ($ownerNav['settings'] as $item)
                            <flux:navmenu.item href="{{ $ownerNavHref($item) }}" :current="$ownerNavCurrent($item)" wire:navigate>
                                {{ $item['label'] }}
                            </flux:navmenu.item>
                        @endforeach
                    </flux:navmenu>
                </flux:dropdown>
            @endif
        </flux:navbar>

        <flux:spacer />

        <livewire:appearance-switcher />

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

                <form id="signout-form" method="POST" action="{{ route('signout') }}" class="hidden">
                    @csrf
                </form>
                <flux:navmenu.item icon="arrow-right-start-on-rectangle" href="{{ route('signout') }}" onclick="event.preventDefault(); document.getElementById('signout-form').submit();">
                    Sign out
                </flux:navmenu.item>
            </flux:navmenu>
        </flux:dropdown>
    </flux:header>

    <flux:sidebar collapsible="mobile" sticky class="dark lg:hidden border-r border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-brand :href="$homeRoute" variant="authenticated" />

            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:navlist>
            @if ($isAdmin)
                <flux:navlist.item icon="squares-2x2" href="{{ route('admin.dashboard') }}" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                    Overview
                </flux:navlist.item>

                <flux:navlist.group heading="Support" expandable>
                    <flux:navlist.item href="{{ route('admin.tickets') }}" :current="request()->routeIs('admin.tickets*')" wire:navigate>Tickets</flux:navlist.item>
                    <flux:navlist.item href="{{ route('admin.support.settings') }}" :current="request()->routeIs('admin.support.settings')" wire:navigate>Support Settings</flux:navlist.item>
                    <flux:navlist.item href="{{ route('admin.faqs') }}" :current="request()->routeIs('admin.faqs')" wire:navigate>FAQs</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.item icon="users" href="{{ route('admin.users') }}" :current="request()->routeIs('admin.users*')" wire:navigate>
                    Users
                </flux:navlist.item>

                <flux:navlist.group heading="Content" expandable>
                    <flux:navlist.item href="{{ route('admin.announcement') }}" :current="request()->routeIs('admin.announcement')" wire:navigate>Announcement</flux:navlist.item>
                    <flux:navlist.item href="{{ route('admin.legal.edit', 'privacy') }}" :current="request()->is('admin/legal/privacy')" wire:navigate>Privacy Policy</flux:navlist.item>
                    <flux:navlist.item href="{{ route('admin.legal.edit', 'terms') }}" :current="request()->is('admin/legal/terms')" wire:navigate>Terms of Service</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group heading="System" expandable>
                    <flux:navlist.item href="{{ route('admin.environment') }}" :current="request()->routeIs('admin.environment')" wire:navigate>Environment</flux:navlist.item>
                    <flux:navlist.item href="{{ route('admin.logs') }}" :current="request()->routeIs('admin.logs')" wire:navigate>Logs</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group heading="Security" expandable>
                    <flux:navlist.item href="{{ route('admin.security.threats') }}" :current="request()->routeIs('admin.security.threats')" wire:navigate>Threats</flux:navlist.item>
                    <flux:navlist.item href="{{ route('admin.security.fraud') }}" :current="request()->routeIs('admin.security.fraud')" wire:navigate>Fraud</flux:navlist.item>
                    <flux:navlist.item href="{{ route('admin.security.forbidden-log') }}" :current="request()->routeIs('admin.security.forbidden-log')" wire:navigate>Forbidden Log</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group heading="Finance" expandable>
                    <flux:navlist.item href="{{ $adminHref('admin.owners', '/admin/owners') }}" :current="request()->is('admin/owners*')" wire:navigate>Website Owners</flux:navlist.item>
                    <flux:navlist.item href="{{ $adminHref('admin.withdrawals', '/admin/withdrawals') }}" :current="request()->is('admin/withdrawals*')" wire:navigate>Withdrawal Queue</flux:navlist.item>
                    <flux:navlist.item href="{{ $adminHref('admin.treasury', '/admin/treasury') }}" :current="request()->is('admin/treasury')" wire:navigate>Treasury</flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group heading="Platform" expandable>
                    <flux:navlist.item href="{{ $adminHref('admin.platform-settings', '/admin/platform-settings') }}" :current="request()->is('admin/platform-settings')" wire:navigate>Platform Settings</flux:navlist.item>
                    <flux:navlist.item href="{{ $adminHref('admin.withdrawal-settings', '/admin/withdrawal-settings') }}" :current="request()->is('admin/withdrawal-settings')" wire:navigate>Withdrawal Settings</flux:navlist.item>
                </flux:navlist.group>
            @else
                @foreach ($ownerNav['main'] as $item)
                    <flux:navlist.item icon="{{ $item['icon'] }}" href="{{ $ownerNavHref($item) }}" :current="$ownerNavCurrent($item)" wire:navigate>
                        {{ $item['label'] }}
                    </flux:navlist.item>
                @endforeach

                @if (! empty($ownerNav['settings']))
                    <flux:navlist.group heading="Settings" expandable>
                        @foreach ($ownerNav['settings'] as $item)
                            <flux:navlist.item icon="{{ $item['icon'] }}" href="{{ $ownerNavHref($item) }}" :current="$ownerNavCurrent($item)" wire:navigate>
                                {{ $item['label'] }}
                            </flux:navlist.item>
                        @endforeach
                    </flux:navlist.group>
                @endif
            @endif
        </flux:navlist>
    </flux:sidebar>

    <flux:main container>
        {{ $slot }}
    </flux:main>

    @unless ($isAdmin)
        <livewire:support.announcement-modal />
    @endunless

    <flux:toast />
    @fluxScripts
</body>
</html>
