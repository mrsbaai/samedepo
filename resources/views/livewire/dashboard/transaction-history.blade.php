<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">Transactions</flux:heading>
        <flux:subheading class="mt-2">Full ledger of deposits, withdrawals and adjustments.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load transactions">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <flux:skeleton class="h-9 w-32" />
            <flux:skeleton class="h-9 w-32" />
            <flux:skeleton class="h-9 w-32" />
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Time</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column class="max-md:hidden">Reference</flux:table.column>
                <flux:table.column class="max-md:hidden">Network</flux:table.column>
                <flux:table.column align="end">Gross</flux:table.column>
                <flux:table.column align="end">Net</flux:table.column>
                <flux:table.column align="end" class="max-lg:hidden">Net USD</flux:table.column>
                <flux:table.column class="max-md:hidden">Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        @foreach (range(1, 9) as $c)
                            <flux:table.cell @class(['max-md:hidden' => in_array($c, [3, 4, 8]), 'max-lg:hidden' => $c === 7])><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="Search reference or tx hash..." size="sm" class="w-full sm:max-w-xs" clearable />
            <flux:select size="sm" wire:model.live="typeFilter" class="w-auto">
                <flux:select.option value="all">All types</flux:select.option>
                <flux:select.option value="deposit">Deposits</flux:select.option>
                <flux:select.option value="withdrawal">Withdrawals</flux:select.option>
                <flux:select.option value="adjustment">Adjustments</flux:select.option>
            </flux:select>
            <flux:select size="sm" wire:model.live="networkFilter" class="w-auto">
                <flux:select.option value="all">All networks</flux:select.option>
                @foreach ($this->networkOptions as $slug => $label)
                    <flux:select.option value="{{ $slug }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select size="sm" wire:model.live="statusFilter" class="w-auto">
                <flux:select.option value="all">All statuses</flux:select.option>
                @foreach ($this->statusOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:date-picker mode="range" size="sm" wire:model.live="range" clearable />
            @if ($this->hasFilters)
                <flux:button variant="ghost" size="sm" wire:click="clearFilters" icon="x-mark">Clear</flux:button>
            @endif
        </div>

        @if ($this->paginatedEntries->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="arrows-right-left" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">
                    @if ($this->hasFilters)
                        No transactions match your filters. Try a different combination.
                    @else
                        No transactions yet. Deposits and withdrawals will show up here once they happen.
                    @endif
                </flux:text>
            </div>
        @else
            @php
                $statusColors = [
                    'detected' => 'zinc',
                    'pending' => 'amber',
                    'credited' => 'green',
                    'below_minimum' => 'amber',
                    'forfeited' => 'zinc',
                    'approved' => 'green',
                    'denied' => 'zinc',
                    'cancelled' => 'zinc',
                    'sent' => 'green',
                ];
            @endphp
            <flux:table :paginate="$this->paginatedEntries" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column>Time</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column class="max-md:hidden">Reference</flux:table.column>
                    <flux:table.column class="max-md:hidden">Network</flux:table.column>
                    <flux:table.column align="end">Gross</flux:table.column>
                    <flux:table.column align="end">Net</flux:table.column>
                    <flux:table.column align="end" class="max-lg:hidden">Net USD</flux:table.column>
                    <flux:table.column class="max-md:hidden">Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->paginatedEntries as $tx)
                        <flux:table.row wire:key="tx-{{ $tx['id'] }}">
                            <flux:table.cell class="whitespace-nowrap">
                                <x-date.human :at="$tx['timestamp']" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="{{ $tx['type'] === 'deposit' ? 'green' : 'amber' }}">{{ ucfirst($tx['type']) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                @if ($tx['customer'])
                                    <flux:link href="{{ route('customers.show', $tx['customer']) }}" wire:navigate>{{ $tx['userRef'] }}</flux:link>
                                @elseif (str_contains($tx['userRef'] ?? '', 'Consolidation'))
                                    <span class="inline-flex items-center gap-1">
                                        {{ $tx['userRef'] }}
                                        <flux:tooltip toggleable content="Consolidation is the network cost of sweeping funds from your customers' deposit addresses into one address before your withdrawal is sent."><button type="button" class="inline-flex text-zinc-400"><flux:icon.information-circle variant="mini" class="size-4" /></button></flux:tooltip>
                                    </span>
                                @else
                                    {{ $tx['userRef'] ?? '—' }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                <span class="flex items-center gap-1.5">
                                    <x-crypto-icon :icon="$tx['icon']" :badge="$tx['badge']" class="size-4" />
                                    {{ $tx['networkLabel'] }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="font-ledger">
                                {{ $tx['gross'] }} {{ $tx['symbol'] }}
                            </flux:table.cell>
                            <flux:table.cell align="end" variant="strong" class="font-ledger">
                                @if ($tx['net'] !== null)
                                    {{ $tx['net'] }} {{ $tx['symbol'] }}
                                @else
                                    &mdash;
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="max-lg:hidden font-ledger whitespace-nowrap">
                                {{ $tx['usd'] ?? '—' }}
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden py-0">
                                <flux:badge size="sm" color="{{ $statusColors[$tx['status']] ?? 'zinc' }}">{{ $tx['statusLabel'] }}@if (str_contains($tx['statusLabel'], 'Consolidation')) <flux:tooltip toggleable content="Consolidation is the network cost of sweeping funds from your customers' deposit addresses into one address before your withdrawal is sent."><button type="button" class="inline-flex text-zinc-400"><flux:icon.information-circle variant="mini" class="size-4" /></button></flux:tooltip>@endif</flux:badge>
                                @if (in_array($tx['status'], ['below_minimum', 'forfeited'], true))
                                    <x-short-payment-note :row="$tx" />
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                @if ($tx['txHash'])
                                    <x-hash-actions :value="$tx['txHash']" :url="$tx['explorerUrl']" />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</div>
