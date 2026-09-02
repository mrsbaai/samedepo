<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load customer">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-8 w-48 mb-1" />
        <flux:skeleton class="h-4 w-32 mb-6" />
        <flux:skeleton class="h-4 w-32 mb-3" />
        <div class="space-y-2">
            @foreach (range(1, 3) as $i)
                <flux:skeleton class="h-10 w-full max-w-md" />
            @endforeach
        </div>
    @else
        <div class="mb-6">
            <flux:heading size="xl">{{ $this->customer->customer_reference }}</flux:heading>
            <flux:text variant="subtle" size="sm" class="mt-1">
                Customer since {{ $this->customer->created_at->format('M j, Y') }}
                · {{ $this->deposits->total() }} deposits
            </flux:text>
        </div>

        <flux:heading size="lg" class="mb-3">Deposit Addresses</flux:heading>

        <x-customer.addresses :addresses="$this->addresses" />

        <flux:heading size="lg" class="mt-8 mb-3">Deposits</flux:heading>

        <x-customer.deposits-table :deposits="$this->deposits" />

        <div class="mt-8">
            <flux:button variant="ghost" icon="arrow-left" href="{{ route('customers') }}" wire:navigate>Back to Customers</flux:button>
        </div>
    @endif
</div>
