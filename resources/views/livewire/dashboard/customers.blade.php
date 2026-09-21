<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">Customers</flux:heading>
        <flux:subheading class="mt-2">Search and browse customers registered under your account.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load customers">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-9 w-64 mb-6" />
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Registered</flux:table.column>
                <flux:table.column>Reference</flux:table.column>
                <flux:table.column>Total USD</flux:table.column>
                <flux:table.column>Deposits</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-12" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <div class="mb-6">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="Search by reference..." size="sm" class="max-w-xs" clearable />
        </div>

        @if ($this->paginatedCustomers->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="users" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">
                    {{ $this->search ? 'No customers match your search.' : 'No customers yet. Customers appear here once you register them through the API.' }}
                </flux:text>
            </div>
        @else
            <flux:table :paginate="$this->paginatedCustomers" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sort === 'created_at'" :direction="$direction" wire:click="sortBy('created_at')" class="max-md:hidden">Registered</flux:table.column>
                    <flux:table.column>Reference</flux:table.column>
                    @foreach ($this->networkColumns as $key => $meta)
                        <flux:table.column class="max-lg:hidden">{{ $meta['label'] }}</flux:table.column>
                    @endforeach
                    <flux:table.column sortable :sorted="$sort === 'total_usd'" :direction="$direction" wire:click="sortBy('total_usd')">Total USD</flux:table.column>
                    <flux:table.column sortable :sorted="$sort === 'deposits_count'" :direction="$direction" wire:click="sortBy('deposits_count')" class="max-md:hidden">Deposits</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->paginatedCustomers as $customer)
                        <flux:table.row wire:key="cust-{{ $customer->id }}">
                            <flux:table.cell class="max-md:hidden whitespace-nowrap">
                                <x-date.human :at="$customer->created_at" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:link href="{{ route('customers.show', $customer) }}" variant="strong" wire:navigate>{{ $customer->customer_reference }}</flux:link>
                            </flux:table.cell>
                            @foreach ($this->networkColumns as $key => $meta)
                                <flux:table.cell class="max-lg:hidden whitespace-nowrap font-ledger">
                                    @if ((float) ($customer->{"usd_{$key}"} ?? 0) > 0)
                                        ${{ number_format((float) $customer->{"usd_{$key}"}, 2) }}
                                    @else
                                        <span class="text-zinc-400">&mdash;</span>
                                    @endif
                                </flux:table.cell>
                            @endforeach
                            <flux:table.cell variant="strong" class="font-ledger whitespace-nowrap">
                                ${{ number_format((float) ($customer->total_usd ?? 0), 2) }}
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden font-ledger">
                                {{ $customer->deposits_count }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</div>
