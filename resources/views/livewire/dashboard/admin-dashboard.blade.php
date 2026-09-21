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
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <flux:icon name="clock" class="size-5 text-zinc-400" />
                <flux:heading size="md">Pending withdrawals</flux:heading>
                <flux:badge size="sm" color="amber">{{ number_format($pendingWithdrawals['count']) }}</flux:badge>
            </div>
            @if ($pendingWithdrawals['count'] > count($pendingWithdrawals['items']))
                <flux:link href="{{ route('admin.withdrawals') }}" variant="subtle">View all</flux:link>
            @endif
        </div>

        @if ($pendingWithdrawals['items']->isEmpty())
            <flux:text class="mt-4 text-zinc-500">No pending withdrawals.</flux:text>
        @else
            <flux:table container:class="mt-4">
                <flux:table.columns>
                    <flux:table.column><span class="max-lg:hidden">Owner</span></flux:table.column>
                    <flux:table.column class="max-sm:hidden">Network</flux:table.column>
                    <flux:table.column align="end">Amount</flux:table.column>
                    <flux:table.column class="max-md:hidden">Requested</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($pendingWithdrawals['items'] as $withdrawal)
                        @php
                            $meta = $networkMeta[$withdrawal->network] ?? ['label' => $withdrawal->network, 'symbol' => '', 'decimals' => 8];
                        @endphp
                        <flux:table.row wire:key="pw-{{ $withdrawal->id }}">
                            <flux:table.cell variant="strong" class="max-w-48 truncate max-lg:max-w-full">{{ $withdrawal->user?->email ?? 'Unknown owner' }}</flux:table.cell>
                            <flux:table.cell class="max-sm:hidden whitespace-nowrap">{{ $meta['label'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap font-mono">
                                {{ $this->formattedAmount((float) $withdrawal->gross_amount, $meta['decimals']) }} {{ $meta['symbol'] }}
                                <flux:text size="sm" variant="subtle">${{ $this->usdValue((float) $withdrawal->gross_amount, $withdrawal->network) }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden whitespace-nowrap">{{ \App\Support\Dates::humanFlat($withdrawal->created_at) }}</flux:table.cell>
                            <flux:table.cell class="py-0">
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button size="sm" variant="primary" wire:click="approve({{ $withdrawal->id }})">Accept</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="deny({{ $withdrawal->id }})" wire:confirm="Are you sure you want to decline this withdrawal? The full amount will be returned to the owner.">Decline</flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
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

    <section wire:poll.visible.10s="refreshTreasuryData">
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

        <div class="mt-5 grid grid-cols-2 gap-6 sm:grid-cols-4">
            <div>
                <flux:text size="sm" variant="subtle">Withdrawable profit</flux:text>
                <flux:heading size="xl" class="mt-1">${{ number_format((float) $treasury['totalWithdrawableUsd'], 2) }}</flux:heading>
            </div>
            <div>
                <flux:text size="sm" variant="subtle">Total profit</flux:text>
                <flux:heading size="xl" class="mt-1 {{ bccomp($treasury['totalEquityUsd'], '0', 8) < 0 ? 'text-red-600 dark:text-red-400' : '' }}">${{ number_format((float) $treasury['totalEquityUsd'], 2) }}</flux:heading>
            </div>
            <div>
                <flux:text size="sm" variant="subtle">Unswept funds</flux:text>
                <flux:heading size="xl" class="mt-1">${{ number_format((float) $treasury['unsweptUsd'], 2) }}</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">{{ $treasury['unsweptAddresses'] }} {{ Str::plural('address', $treasury['unsweptAddresses']) }}</flux:text>
            </div>
            <div>
                <flux:text size="sm" variant="subtle">Failed ops (24h)</flux:text>
                <flux:heading size="xl" class="mt-1 {{ $treasury['failures24h'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ number_format($treasury['failures24h']) }}</flux:heading>
            </div>
        </div>

        <flux:table container:class="mt-6">
            <flux:table.columns>
                <flux:table.column>Network</flux:table.column>
                <flux:table.column align="end">Withdrawable</flux:table.column>
                <flux:table.column align="end" class="max-md:hidden">Total profit</flux:table.column>
                <flux:table.column align="end" class="max-md:hidden">Unswept</flux:table.column>
                <flux:table.column class="max-lg:hidden">Gas</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($treasury['networks'] as $network => $n)
                    @php
                        $meta = $networkMeta[$network] ?? ['label' => $network, 'symbol' => '', 'decimals' => 8];
                        $gasState = $treasury['gas'][$network] ?? 'not_applicable';
                        $gasLabel = ['not_applicable' => '—', 'ready' => 'Ready', 'low' => 'Low gas', 'paused' => 'Paused', 'unknown' => 'Unknown'][$gasState];
                        $gasColor = ['not_applicable' => 'zinc', 'ready' => 'green', 'low' => 'amber', 'paused' => 'zinc', 'unknown' => 'zinc'][$gasState];
                    @endphp
                    <flux:table.row :key="'treasury-'.$network">
                        <flux:table.cell variant="strong" class="whitespace-nowrap">{{ $meta['label'] }}</flux:table.cell>
                        <flux:table.cell align="end" class="whitespace-nowrap font-mono">
                            {{ number_format((float) $n['withdrawable'], $meta['decimals']) }} {{ $meta['symbol'] }}
                            <flux:text size="sm" variant="subtle">${{ number_format((float) $n['withdrawable_usd'], 2) }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="max-md:hidden whitespace-nowrap font-mono {{ bccomp($n['equity'], '0', 8) < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                            {{ number_format((float) $n['equity'], $meta['decimals']) }} {{ $meta['symbol'] }}
                            <flux:text size="sm" variant="subtle">${{ number_format((float) $n['equity_usd'], 2) }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="max-md:hidden whitespace-nowrap font-mono">
                            {{ number_format((float) $n['unswept'], $meta['decimals']) }} {{ $meta['symbol'] }}
                            <flux:text size="sm" variant="subtle">${{ number_format((float) $n['unswept_usd'], 2) }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell class="max-lg:hidden py-0">
                            <flux:badge size="sm" :color="$gasColor">{{ $gasLabel }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if ($treasury['anyAddressMissing'])
            <flux:text size="sm" class="mt-4 text-zinc-500">A profit payout address is missing. <flux:link href="{{ route('admin.platform-settings') }}">Set payout address</flux:link></flux:text>
        @endif
    </section>

    <section>
        <livewire:dashboard.income-charts :owner-id="null" heading="Platform income" />
    </section>
</div>
