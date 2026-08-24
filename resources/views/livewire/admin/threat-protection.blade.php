<div class="mx-auto max-w-7xl space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Threat Protection</flux:heading>
            <flux:text class="mt-1">Are we being attacked, what is being detected, and what is currently blocked?</flux:text>
        </div>
        <flux:button href="{{ route('admin.security.fraud') }}" variant="ghost" icon-trailing="arrow-right" size="sm" wire:navigate>
            Fraud Intelligence
        </flux:button>
    </div>

    {{-- Top summary --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>Threats today</flux:subheading>
            <flux:heading size="xl">{{ number_format($threatsToday) }}</flux:heading>
        </div>
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>Blocked IPs</flux:subheading>
            <flux:heading size="xl">{{ number_format($blockedIps) }}</flux:heading>
        </div>
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>Blocked devices</flux:subheading>
            <flux:heading size="xl">{{ number_format($blockedDevices) }}</flux:heading>
        </div>
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>Attacking IPs (1h)</flux:subheading>
            <flux:heading size="xl">{{ number_format($activeAttacks) }}</flux:heading>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="period" size="sm" label="Time">
            <flux:select.option value="1h">Last hour</flux:select.option>
            <flux:select.option value="24h">Last 24 hours</flux:select.option>
            <flux:select.option value="7d">Last 7 days</flux:select.option>
            <flux:select.option value="30d">Last 30 days</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="severity" size="sm" label="Severity">
            <flux:select.option value="">All</flux:select.option>
            <flux:select.option value="critical">Critical</flux:select.option>
            <flux:select.option value="high">High</flux:select.option>
            <flux:select.option value="medium">Medium</flux:select.option>
            <flux:select.option value="low">Low</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="detector" size="sm" label="Detector">
            <flux:select.option value="">All</flux:select.option>
            @foreach ($detectors as $detectorOption)
                <flux:select.option value="{{ $detectorOption['key'] }}">{{ $detectorOption['key'] }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.400ms="ip" size="sm" label="IP" placeholder="185.123." class="max-w-40" />
    </div>

    {{-- Live threats --}}
    <div>
        <flux:heading size="lg" class="mb-3">Live threats</flux:heading>

        @if ($events->isEmpty())
            <flux:text>No threats detected in this period.</flux:text>
        @else
            <flux:table :paginate="$events">
                <flux:table.columns>
                    <flux:table.column>Time</flux:table.column>
                    <flux:table.column>Severity</flux:table.column>
                    <flux:table.column>Threat</flux:table.column>
                    <flux:table.column class="max-md:hidden">IP</flux:table.column>
                    <flux:table.column class="max-md:hidden">Endpoint</flux:table.column>
                    <flux:table.column>Action</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($events as $event)
                        <flux:table.row :key="$event->id">
                            <flux:table.cell class="whitespace-nowrap">{{ $event->created_at->format('H:i:s') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" inset="top bottom" :color="match($event->severityLabel()) {
                                    'critical' => 'red', 'high' => 'orange', 'medium' => 'yellow', default => 'zinc',
                                }">{{ strtoupper($event->severityLabel()) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell variant="strong">{{ str($event->threat_type)->replace('_', ' ')->title() }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden font-mono text-xs">{{ $event->ip_address }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden max-w-48 truncate font-mono text-xs">{{ $event->method }} {{ $event->path }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($event->blocked)
                                    <flux:badge size="sm" color="red" inset="top bottom">BLOCKED</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc" inset="top bottom">Recorded</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="sm" variant="ghost" inset="top bottom" wire:click="showEvent({{ $event->id }})">Detail</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        {{-- Threat breakdown --}}
        <div>
            <flux:heading size="lg" class="mb-3">Threats by detector</flux:heading>
            @if ($breakdown->isEmpty())
                <flux:text>Nothing detected in this period.</flux:text>
            @else
                <div class="space-y-2">
                    @foreach ($breakdown as $detectorName => $total)
                        <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-2 dark:bg-zinc-800">
                            <flux:text variant="strong">{{ $detectorName }}</flux:text>
                            <flux:badge size="sm">{{ number_format($total) }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Detector status --}}
        <div>
            <flux:heading size="lg" class="mb-3">Detectors</flux:heading>
            <div class="space-y-2">
                @foreach ($detectors as $detectorOption)
                    <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-2 dark:bg-zinc-800">
                        <flux:text variant="strong">{{ $detectorOption['key'] }}</flux:text>
                        <flux:switch :checked="$detectorOption['enabled']" wire:click="toggleDetector('{{ $detectorOption['key'] }}')" />
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Blocklist --}}
    <div>
        <flux:heading size="lg" class="mb-3">Blocklist</flux:heading>

        <form wire:submit="block" class="mb-4 flex flex-wrap items-end gap-3">
            <flux:select wire:model="blockType" size="sm" label="Type" class="max-w-32">
                <flux:select.option value="ip">IP</flux:select.option>
                <flux:select.option value="device">Device</flux:select.option>
            </flux:select>
            <flux:input wire:model="blockValue" size="sm" label="Value" placeholder="1.2.3.4 or fingerprint" class="max-w-56" />
            <flux:input wire:model="blockReason" size="sm" label="Reason" placeholder="Optional" class="max-w-56" />
            <flux:button type="submit" size="sm" variant="danger">Block</flux:button>
        </form>

        @if ($blocks->isEmpty())
            <flux:text>Nothing is blocked.</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Value</flux:table.column>
                    <flux:table.column class="max-md:hidden">Reason</flux:table.column>
                    <flux:table.column class="max-md:hidden">Source</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($blocks as $blockEntry)
                        <flux:table.row :key="$blockEntry->id">
                            <flux:table.cell>{{ strtoupper($blockEntry->type) }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">{{ $blockEntry->value }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden max-w-64 truncate">{{ $blockEntry->reason }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ str($blockEntry->source)->replace('_', ' ') }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($blockEntry->type === 'ip')
                                    <flux:button size="sm" variant="ghost" inset="top bottom" wire:click="unblockIp('{{ $blockEntry->value }}')">Unblock</flux:button>
                                @else
                                    <flux:button size="sm" variant="ghost" inset="top bottom" wire:click="unblockDevice('{{ $blockEntry->value }}')">Unblock</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    {{-- Threat detail --}}
    <flux:modal wire:model.self="selectedEventId" name="threat-detail" variant="dialog" class="md:w-[32rem]">
        @if ($selectedEvent)
            <div class="space-y-4">
                <div>
                    <flux:badge :color="match($selectedEvent->severityLabel()) {
                        'critical' => 'red', 'high' => 'orange', 'medium' => 'yellow', default => 'zinc',
                    }">{{ strtoupper($selectedEvent->severityLabel()) }}</flux:badge>
                    <flux:heading size="lg" class="mt-2">{{ str($selectedEvent->threat_type)->replace('_', ' ')->title() }}</flux:heading>
                </div>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-zinc-500">IP</dt><dd class="font-mono">{{ $selectedEvent->ip_address }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-zinc-500">Device</dt><dd class="max-w-56 truncate font-mono">{{ $selectedEvent->fingerprint ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-zinc-500">Endpoint</dt><dd class="max-w-64 truncate font-mono">{{ $selectedEvent->method }} {{ $selectedEvent->path }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-zinc-500">Detected by</dt><dd>{{ $selectedEvent->detector }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-zinc-500">Confidence</dt><dd>{{ $selectedEvent->confidence() }}%</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-zinc-500">When</dt><dd>{{ $selectedEvent->created_at->format('Y-m-d H:i:s') }}</dd></div>
                </dl>

                <div>
                    <flux:subheading>Reason</flux:subheading>
                    <flux:text class="mt-1">{{ $selectedEvent->description }}</flux:text>
                </div>

                @if ($selectedEvent->payload)
                    <div>
                        <flux:subheading>Payload</flux:subheading>
                        <pre class="mt-1 max-h-32 overflow-auto rounded bg-zinc-100 p-2 text-xs dark:bg-zinc-800">{{ $selectedEvent->payload }}</pre>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    @if ($ipBlocked($selectedEvent->ip_address))
                        <flux:button size="sm" wire:click="unblockIp('{{ $selectedEvent->ip_address }}')">Unblock IP</flux:button>
                    @endif
                    @if ($selectedEvent->fingerprint && $deviceBlocked($selectedEvent->fingerprint))
                        <flux:button size="sm" wire:click="unblockDevice('{{ $selectedEvent->fingerprint }}')">Unblock device</flux:button>
                    @endif
                    <flux:spacer />
                    <flux:button size="sm" variant="ghost" href="{{ route('admin.security.fraud') }}" wire:navigate>View in Fraud Engine</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
