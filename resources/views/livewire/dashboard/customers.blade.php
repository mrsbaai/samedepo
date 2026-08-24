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
                <flux:table.column>Customer reference</flux:table.column>
                <flux:table.column class="max-md:hidden">Registered</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-20" /></flux:table.cell>
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
                    <flux:table.column>Customer reference</flux:table.column>
                    <flux:table.column class="max-md:hidden">Registered</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->paginatedCustomers as $customer)
                        <flux:table.row wire:key="cust-{{ $customer->id }}">
                            <flux:table.cell variant="strong">{{ $customer->customer_reference }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden whitespace-nowrap">
                                <flux:tooltip content="{{ $customer->created_at->format('M j, Y H:i') }} UTC">
                                    <span>{{ $customer->created_at->diffForHumans() }}</span>
                                </flux:tooltip>
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                <flux:button variant="ghost" size="sm" icon="chevron-right" href="{{ route('customers.show', $customer) }}" wire:navigate />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</div>
