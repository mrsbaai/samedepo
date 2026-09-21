<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load website owners">
            <flux:callout.text>Couldn't load website owners. Please try again.</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-9 w-64 mb-6" />
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Created</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Customers</flux:table.column>
                <flux:table.column>Total earned</flux:table.column>
                <flux:table.column>Balance</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 4) as $r)
                    <flux:table.row>
                        @foreach (range(1, 6) as $c)
                            <flux:table.cell><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <flux:heading size="xl" class="mb-6">Website Owners</flux:heading>

        <div class="mb-6">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="Search by email or ID..." size="sm" class="max-w-xs" clearable />
        </div>

        @if ($owners->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="users" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">{{ $search ? 'No owners match your search.' : 'No website owner accounts yet.' }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column
                        sortable
                        :sorted="$sort === 'created_at'"
                        :direction="$direction"
                        wire:click="sort('created_at')"
                    >Created</flux:table.column>
                    <flux:table.column
                        sortable
                        :sorted="$sort === 'email'"
                        :direction="$direction"
                        wire:click="sort('email')"
                    >Email</flux:table.column>
                    <flux:table.column
                        sortable
                        :sorted="$sort === 'customers_count'"
                        :direction="$direction"
                        wire:click="sort('customers_count')"
                    >Customers</flux:table.column>
                    <flux:table.column
                        sortable
                        align="end"
                        :sorted="$sort === 'earned_usd'"
                        :direction="$direction"
                        wire:click="sort('earned_usd')"
                    >Total earned</flux:table.column>
                    <flux:table.column
                        sortable
                        align="end"
                        :sorted="$sort === 'balance_usd'"
                        :direction="$direction"
                        wire:click="sort('balance_usd')"
                    >Balance</flux:table.column>
                    <flux:table.column
                        sortable
                        :sorted="$sort === 'status'"
                        :direction="$direction"
                        wire:click="sort('status')"
                    >Status</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($owners as $owner)
                        <flux:table.row wire:key="owner-{{ $owner->id }}">
                            <flux:table.cell class="whitespace-nowrap">{{ \App\Support\Dates::humanFlat($owner->created_at) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:link href="{{ route('admin.owners.show', $owner) }}" variant="strong" wire:navigate>{{ $owner->email }}</flux:link>
                            </flux:table.cell>
                            <flux:table.cell class="font-ledger">{{ $owner->customers_count }}</flux:table.cell>
                            <flux:table.cell align="end" class="font-ledger">${{ number_format((float) $owner->earned_usd, 2) }}</flux:table.cell>
                            <flux:table.cell align="end" variant="strong" class="font-ledger">${{ number_format((float) $owner->balance_usd, 2) }}</flux:table.cell>
                            <flux:table.cell class="py-0">
                                <flux:badge size="sm" :color="$owner->is_active ? 'green' : 'zinc'">
                                    {{ $owner->is_active ? 'Active' : 'Inactive' }}
                                </flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="mt-6">
                {{ $owners->links() }}
            </div>
        @endif
    @endif
</div>
