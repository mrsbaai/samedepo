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
            </flux:text>
        </div>

        <flux:heading size="lg" class="mb-3">Deposit Addresses</flux:heading>

        @if (empty($this->addresses))
            <div class="py-12 text-center">
                <flux:icon icon="wallet" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">No deposit addresses found for this customer.</flux:text>
            </div>
        @else
            <div class="space-y-2 mb-8">
                @foreach ($this->addresses as $addr)
                    <div class="flex items-center gap-3" wire:key="addr-{{ $addr['networkSlug'] }}">
                        <img src="{{ asset('crypto/' . $addr['networkSlug'] . '.svg') }}" alt="" class="size-4 shrink-0" />
                        <flux:text size="sm" class="w-28 shrink-0">{{ $addr['networkLabel'] }}</flux:text>
                        <code class="text-xs truncate flex-1 text-zinc-600 dark:text-zinc-400 font-ledger">{{ $addr['address'] }}</code>
                        <flux:tooltip content="Copy address">
                            <flux:button variant="ghost" size="sm" icon="clipboard-document" onclick="navigator.clipboard.writeText('{{ $addr['address'] }}')" />
                        </flux:tooltip>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-8">
            <flux:button variant="ghost" icon="arrow-left" href="{{ route('customers') }}" wire:navigate>Back to Customers</flux:button>
        </div>
    @endif
</div>
