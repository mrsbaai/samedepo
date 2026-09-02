<div class="py-8 space-y-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load customer">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'not-found')
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Customer not found">
            <flux:callout.text>This customer doesn't exist under this owner.</flux:callout.text>
            <x-slot name="actions">
                @if ($this->ownerRecord)
                    <flux:button variant="ghost" href="{{ route('admin.owners.show', $this->ownerRecord) }}" wire:navigate>Back to owner</flux:button>
                @else
                    <flux:button variant="ghost" href="{{ route('admin.owners') }}" wire:navigate>Back to Owners</flux:button>
                @endif
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-8 w-48" />
        <flux:skeleton class="h-4 w-64" />
        <flux:skeleton class="h-48 w-full" />
    @elseif ($this->customerRecord)
        <div class="space-y-6">
            <div>
                <flux:text size="sm" class="flex items-center gap-1">
                    <flux:link href="{{ route('admin.owners') }}" wire:navigate>Owners</flux:link>
                    <span>›</span>
                    <flux:link href="{{ route('admin.owners.show', $this->ownerRecord) }}" wire:navigate>{{ $this->ownerRecord->email }}</flux:link>
                </flux:text>

                <flux:heading size="xl" class="mt-1">{{ $this->customerRecord->customer_reference }}</flux:heading>

                <flux:text variant="subtle" size="sm" class="mt-1">
                    Customer since {{ $this->customerRecord->created_at->format('M j, Y') }}
                    · {{ $this->stats['count'] }} deposits
                    · ${{ number_format((float) $this->stats['usd'], 2) }} deposited
                </flux:text>
            </div>

            <div>
                <flux:heading size="lg" class="mb-3">Deposit addresses</flux:heading>
                <x-customer.addresses :addresses="$this->addresses" />
            </div>

            <div>
                <flux:heading size="lg" class="mb-3">Deposits</flux:heading>
                <x-customer.deposits-table :deposits="$this->deposits" />
            </div>
        </div>
    @endif
</div>
