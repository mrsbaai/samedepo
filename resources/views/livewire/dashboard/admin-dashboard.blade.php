<div class="space-y-8">
    <div>
        <flux:heading size="xl">Overview</flux:heading>
        <flux:subheading class="mt-2">Platform activity and operational status.</flux:subheading>
    </div>

    @if ($tickets->isNotEmpty())
        <section>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <flux:icon name="ticket" class="size-5 text-zinc-400" />
                    <flux:heading size="md">Open support tickets</flux:heading>
                </div>
                <flux:link href="{{ route('admin.tickets') }}" variant="subtle">View all tickets</flux:link>
            </div>

            <div class="mt-3">
                @include('components.admin.open-tickets', ['tickets' => $tickets])
            </div>
        </section>
    @endif

    <section>
        <flux:card variant="soft">
            <div class="flex items-center gap-2">
                <flux:icon name="chart-bar" class="size-5 text-zinc-400" />
                <flux:heading size="md">Platform status</flux:heading>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-5">
                <div>
                    <div class="flex items-center gap-1.5 text-zinc-500">
                        <flux:icon name="users" variant="outline" class="size-4" />
                        <flux:text size="sm">Owners</flux:text>
                    </div>
                    <flux:heading size="xl" class="mt-1">{{ number_format($platformStatus['ownerCount']) }}</flux:heading>
                </div>

                <div>
                    <div class="flex items-center gap-1.5 text-zinc-500">
                        <flux:icon name="user-plus" variant="outline" class="size-4" />
                        <flux:text size="sm">New today</flux:text>
                    </div>
                    <flux:heading size="xl" class="mt-1">{{ number_format($platformStatus['newOwnersToday']) }}</flux:heading>
                </div>

                <div>
                    <div class="flex items-center gap-1.5 text-zinc-500">
                        <flux:icon name="banknotes" variant="outline" class="size-4" />
                        <flux:text size="sm">Deposits (24h)</flux:text>
                    </div>
                    <flux:heading size="xl" class="mt-1">{{ number_format($platformStatus['deposits24h']['count']) }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">${{ number_format($platformStatus['deposits24h']['usdValue'], 2) }}</flux:text>
                </div>

                <div>
                    <div class="flex items-center gap-1.5 text-zinc-500">
                        <flux:icon name="banknotes" variant="outline" class="size-4" />
                        <flux:text size="sm">Deposits (7d)</flux:text>
                    </div>
                    <flux:heading size="xl" class="mt-1">{{ number_format($platformStatus['deposits7d']['count']) }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">${{ number_format($platformStatus['deposits7d']['usdValue'], 2) }}</flux:text>
                </div>

                <div>
                    <div class="flex items-center gap-1.5 text-zinc-500">
                        <flux:icon name="clock" variant="outline" class="size-4" />
                        <flux:text size="sm">Pending withdrawals</flux:text>
                    </div>
                    <flux:heading size="xl" class="mt-1">{{ number_format($platformStatus['pendingWithdrawals']['count']) }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">${{ number_format($platformStatus['pendingWithdrawals']['usdValue'], 2) }}</flux:text>
                </div>
            </div>
        </flux:card>
    </section>

    @php
        $hasSecuritySummary = $securitySummary['events24h'] > 0 || $securitySummary['blockedIps'] > 0 || $securitySummary['blockedDevices'] > 0;
    @endphp

    @if ($hasSecuritySummary)
        <section>
            <flux:card variant="soft">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <flux:icon name="shield-exclamation" class="size-5 text-zinc-400" />
                        <flux:heading size="md">Security summary</flux:heading>
                    </div>

                    <div class="flex items-center gap-3">
                        @if ($securitySummary['status'] === 'active')
                            <flux:badge color="red">Active attack</flux:badge>
                        @elseif ($securitySummary['status'] === 'elevated')
                            <flux:badge color="amber">Elevated</flux:badge>
                        @else
                            <flux:badge color="green">Calm</flux:badge>
                        @endif

                        <flux:link href="{{ route('admin.security.threats') }}" variant="subtle">Investigate</flux:link>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-6">
                    <div>
                        <flux:text size="sm" class="text-zinc-500">Events (1h)</flux:text>
                        <flux:heading size="xl" class="mt-1">{{ number_format($securitySummary['events1h']) }}</flux:heading>
                    </div>
                    <div>
                        <flux:text size="sm" class="text-zinc-500">Events (24h)</flux:text>
                        <flux:heading size="xl" class="mt-1">{{ number_format($securitySummary['events24h']) }}</flux:heading>
                    </div>
                    <div>
                        <flux:text size="sm" class="text-zinc-500">Distinct IPs (1h)</flux:text>
                        <flux:heading size="xl" class="mt-1">{{ number_format($securitySummary['ips1h']) }}</flux:heading>
                    </div>
                    <div>
                        <flux:text size="sm" class="text-zinc-500">Distinct IPs (24h)</flux:text>
                        <flux:heading size="xl" class="mt-1">{{ number_format($securitySummary['ips24h']) }}</flux:heading>
                    </div>
                    <div>
                        <flux:text size="sm" class="text-zinc-500">Blocked IPs</flux:text>
                        <flux:heading size="xl" class="mt-1">{{ number_format($securitySummary['blockedIps']) }}</flux:heading>
                    </div>
                    <div>
                        <flux:text size="sm" class="text-zinc-500">Blocked devices</flux:text>
                        <flux:heading size="xl" class="mt-1">{{ number_format($securitySummary['blockedDevices']) }}</flux:heading>
                    </div>
                </div>
            </flux:card>
        </section>
    @endif
</div>
