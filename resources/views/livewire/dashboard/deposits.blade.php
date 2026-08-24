<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">Deposits</flux:heading>
        <flux:subheading class="mt-2">Monitor detected, pending, and credited deposits in near-real time.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load deposits">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-9 w-64 mb-6" />
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Time</flux:table.column>
                <flux:table.column>Customer</flux:table.column>
                <flux:table.column class="max-md:hidden">Network</flux:table.column>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column class="max-md:hidden">Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-14" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <div class="mb-6">
            <flux:radio.group wire:model.live="statusFilter" variant="segmented" size="sm">
                <flux:radio value="all" label="All" />
                <flux:radio value="detected" label="Detected" />
                <flux:radio value="pending" label="Pending" />
                <flux:radio value="credited" label="Credited" />
            </flux:radio.group>
        </div>

        @if ($this->paginatedDeposits->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="arrow-down-circle" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">
                    @if ($this->statusFilter !== 'all')
                        No deposits match this status. Try a different status filter.
                    @else
                        Nothing here yet. Once a customer sends Bitcoin, USDT (TRC20), or USDT (ERC20) to one of their deposit addresses, it shows up here the moment we detect it.
                    @endif
                </flux:text>
            </div>
        @else
            @php
                $statusColors = ['detected' => 'zinc', 'pending' => 'amber', 'credited' => 'green'];
            @endphp
            <flux:table :paginate="$this->paginatedDeposits" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column>Time</flux:table.column>
                    <flux:table.column>Customer</flux:table.column>
                    <flux:table.column class="max-md:hidden">Network</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column class="max-md:hidden">Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->paginatedDeposits as $dep)
                        <flux:table.row wire:key="dep-{{ $dep['id'] }}">
                            <flux:table.cell class="whitespace-nowrap">
                                <flux:tooltip content="{{ date('M j, Y H:i', strtotime($dep['detectedAt'])) }} UTC">
                                    <span>{{ \Carbon\Carbon::parse($dep['detectedAt'])->diffForHumans() }}</span>
                                </flux:tooltip>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($dep['customer'])
                                    <flux:link href="{{ route('customers.show', $dep['customer']) }}" wire:navigate>{{ $dep['customer']->customer_reference }}</flux:link>
                                @else
                                    &mdash;
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                <span class="flex items-center gap-1.5">
                                    <img src="{{ asset('crypto/' . $dep['networkSlug'] . '.svg') }}" alt="" class="size-4" />
                                    {{ $dep['networkLabel'] }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell variant="strong" class="font-ledger">{{ $dep['amount'] }} {{ $dep['networkSlug'] === 'bitcoin' ? 'BTC' : 'USDT' }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden py-0">
                                <flux:badge size="sm" color="{{ $statusColors[$dep['status']] ?? 'zinc' }}">{{ ucfirst($dep['status']) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                @if ($dep['txHash'])
                                    <flux:tooltip content="Copy tx hash">
                                        <flux:button variant="ghost" size="sm" icon="clipboard-document" class="font-ledger" onclick="navigator.clipboard.writeText('{{ $dep['txHash'] }}')" />
                                    </flux:tooltip>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</div>
