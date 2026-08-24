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
                <flux:table.column>Account</flux:table.column>
                <flux:table.column class="max-md:hidden">Mode</flux:table.column>
                <flux:table.column class="max-md:hidden">Fee Override</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 4) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-40" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-8" /></flux:table.cell>
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
                    <flux:table.column>Account</flux:table.column>
                    <flux:table.column class="max-md:hidden">Mode</flux:table.column>
                    <flux:table.column class="max-md:hidden">Fee Override</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($owners as $owner)
                        <flux:table.row wire:key="owner-{{ $owner->id }}">
                            <flux:table.cell>
                                <div>
                                    <flux:text class="font-medium">{{ $owner->email }}</flux:text>
                                    <flux:text size="sm" variant="subtle">#{{ $owner->id }}</flux:text>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden py-0">
                                @if ($owner->withdrawal_mode === 'instant')
                                    <flux:badge size="sm" color="green">Instant</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">Administrator Approval</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                {{ $owner->deposit_fee_override !== null ? $owner->deposit_fee_override . '%' : 'Platform default' }}
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                <flux:button variant="ghost" size="sm" icon="chevron-right" href="{{ route('admin.owners.show', $owner) }}" wire:navigate />
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
