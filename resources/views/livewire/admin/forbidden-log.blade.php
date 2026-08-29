<div class="mx-auto max-w-7xl space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Forbidden Log</flux:heading>
            <flux:text class="mt-1">Every 403 response, its source, and the reason it was triggered.</flux:text>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>Today</flux:subheading>
            <flux:heading size="xl">{{ number_format($totalToday) }}</flux:heading>
        </div>
        <div class="rounded-lg bg-zinc-50 px-6 py-4 dark:bg-zinc-800">
            <flux:subheading>This period</flux:subheading>
            <flux:heading size="xl">{{ number_format($totalPeriod) }}</flux:heading>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="period" size="sm" label="Time">
            <flux:select.option value="1h">Last hour</flux:select.option>
            <flux:select.option value="24h">Last 24 hours</flux:select.option>
            <flux:select.option value="7d">Last 7 days</flux:select.option>
            <flux:select.option value="30d">Last 30 days</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="source" size="sm" label="Source">
            <flux:select.option value="">All</flux:select.option>
            @foreach ($sources as $sourceOption)
                <flux:select.option value="{{ $sourceOption }}">{{ $sourceOption }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="reason" size="sm" label="Reason">
            <flux:select.option value="">All</flux:select.option>
            @foreach ($reasons as $reasonOption)
                <flux:select.option value="{{ $reasonOption }}">{{ $reasonOption }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.400ms="ip" size="sm" label="IP" placeholder="185.123." class="max-w-40" />
    </div>

    <div>
        <flux:heading size="lg" class="mb-3">Recent 403s</flux:heading>

        @if ($events->isEmpty())
            <flux:text>No 403 responses in this period.</flux:text>
        @else
            <flux:table :paginate="$events">
                <flux:table.columns>
                    <flux:table.column>Time</flux:table.column>
                    <flux:table.column>Source</flux:table.column>
                    <flux:table.column>Reason</flux:table.column>
                    <flux:table.column class="max-md:hidden">IP</flux:table.column>
                    <flux:table.column class="max-md:hidden">Endpoint</flux:table.column>
                    <flux:table.column class="max-md:hidden">User</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($events as $event)
                        <flux:table.row :key="$event->id">
                            <flux:table.cell class="whitespace-nowrap">{{ $event->created_at->format('Y-m-d H:i:s') }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">{{ $event->source }}</flux:table.cell>
                            <flux:table.cell class="max-w-48 truncate">{{ $event->reason }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden font-mono text-xs">{{ $event->ip_address }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden max-w-48 truncate font-mono text-xs">{{ $event->method }} {{ $event->path }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                @if ($event->user_id)
                                    <flux:badge size="sm" color="zinc" inset="top bottom">#{{ $event->user_id }}</flux:badge>
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <div>
            <flux:heading size="lg" class="mb-3">By source</flux:heading>
            @if ($bySource->isEmpty())
                <flux:text>Nothing in this period.</flux:text>
            @else
                <div class="space-y-2">
                    @foreach ($bySource as $sourceName => $total)
                        <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-2 dark:bg-zinc-800">
                            <flux:text variant="strong" class="font-mono text-xs">{{ $sourceName }}</flux:text>
                            <flux:badge size="sm">{{ number_format($total) }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            <flux:heading size="lg" class="mb-3">By reason</flux:heading>
            @if ($byReason->isEmpty())
                <flux:text>Nothing in this period.</flux:text>
            @else
                <div class="space-y-2">
                    @foreach ($byReason as $reasonName => $total)
                        <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-2 dark:bg-zinc-800">
                            <flux:text variant="strong" class="truncate max-w-[80%]">{{ $reasonName }}</flux:text>
                            <flux:badge size="sm">{{ number_format($total) }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
