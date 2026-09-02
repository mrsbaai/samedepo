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

    <section wire:poll.visible.10s="refreshTreasuryData">
        <flux:card variant="soft">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <flux:icon name="banknotes" class="size-5 text-zinc-400" />
                    <flux:heading size="md">Treasury</flux:heading>
                    @php
                        $statusColor = ['healthy' => 'green', 'attention' => 'amber', 'deficit' => 'red'][$treasury['status']];
                    @endphp
                    <flux:badge size="sm" :color="$statusColor">{{ ucfirst($treasury['status']) }}</flux:badge>
                </div>
                <div class="flex items-center gap-2">
                    @if ($treasury['bestNetwork'])
                        <flux:button size="sm" variant="primary" :href="route('admin.treasury', ['payout' => $treasury['bestNetwork']])">Withdraw profit</flux:button>
                    @else
                        <flux:button size="sm" variant="primary" disabled>Withdraw profit</flux:button>
                    @endif
                    <flux:button size="sm" variant="ghost" :href="route('admin.treasury')">Open treasury</flux:button>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-5">
                <div>
                    <flux:text size="sm" class="text-zinc-500">Withdrawable profit</flux:text>
                    <flux:heading size="xl" class="mt-1">${{ number_format((float) $treasury['totalWithdrawableUsd'], 2) }}</flux:heading>
                    @php
                        $lines = collect($treasury['networks'])->filter(fn ($n) => bccomp($n['withdrawable'], '0', 8) > 0);
                    @endphp
                    @forelse ($lines as $network => $n)
                        <flux:text size="sm" class="mt-1 font-mono text-zinc-500">{{ number_format((float) $n['withdrawable'], $networkMeta[$network]['decimals']) }} {{ $networkMeta[$network]['symbol'] }} · {{ $networkMeta[$network]['label'] }}</flux:text>
                    @empty
                        <flux:text size="sm" class="mt-1 text-zinc-500">Nothing yet</flux:text>
                    @endforelse
                </div>
                <div>
                    <flux:text size="sm" class="text-zinc-500">Total profit</flux:text>
                    <flux:heading size="xl" class="mt-1 {{ bccomp($treasury['totalEquityUsd'], '0', 8) < 0 ? 'text-red-600 dark:text-red-400' : '' }}">${{ number_format((float) $treasury['totalEquityUsd'], 2) }}</flux:heading>
                </div>
                <div>
                    <flux:text size="sm" class="text-zinc-500">Unswept funds</flux:text>
                    <flux:heading size="xl" class="mt-1">${{ number_format((float) $treasury['unsweptUsd'], 2) }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">{{ $treasury['unsweptAddresses'] }} {{ Str::plural('address', $treasury['unsweptAddresses']) }}</flux:text>
                </div>
                <div>
                    <flux:text size="sm" class="text-zinc-500">Gas float</flux:text>
                    <div class="mt-2 flex flex-col gap-1">
                        @foreach ($treasury['gas'] as $network => $state)
                            @php
                                $label = ['not_applicable' => 'Not applicable', 'ready' => 'Ready', 'low' => 'Low gas', 'paused' => 'Paused', 'unknown' => 'Unknown'][$state];
                                $color = ['not_applicable' => 'zinc', 'ready' => 'green', 'low' => 'amber', 'paused' => 'zinc', 'unknown' => 'zinc'][$state];
                            @endphp
                            <div class="flex items-center gap-2">
                                <flux:badge size="sm" :color="$color">{{ $label }}</flux:badge>
                                <flux:text size="sm" class="text-zinc-500">{{ $networkMeta[$network]['label'] }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div>
                    <flux:text size="sm" class="text-zinc-500">Failed ops (24h)</flux:text>
                    <flux:heading size="xl" class="mt-1 {{ $treasury['failures24h'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ number_format($treasury['failures24h']) }}</flux:heading>
                </div>
            </div>

            @if ($treasury['anyAddressMissing'])
                <flux:text size="sm" class="mt-4 text-zinc-500">A profit payout address is missing. <flux:link href="{{ route('admin.platform-settings') }}">Set payout address</flux:link></flux:text>
            @endif
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
