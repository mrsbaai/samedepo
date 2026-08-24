<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load treasury data">
            <flux:callout.text>Couldn't load treasury data. Please try again.</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-8 w-48 mb-6" />
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @foreach (range(1, 3) as $i)
                <flux:card variant="soft" class="py-4 px-5">
                    <flux:skeleton class="h-4 w-20 mb-2" />
                    <flux:skeleton class="h-6 w-32 mb-1" />
                    <flux:skeleton class="h-4 w-24" />
                </flux:card>
            @endforeach
        </div>
    @else
        <flux:heading size="xl" class="mb-6">Treasury</flux:heading>

        @if ($this->wallets->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="wallet" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">Treasury wallets have not been provisioned yet.</flux:text>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                @foreach ($this->wallets as $wallet)
                    @php($meta = $this->networkMeta($wallet->network))
                    <flux:card variant="soft" class="py-4 px-5">
                        <span class="flex items-center gap-2">
                            <img src="{{ asset('crypto/'.$meta['slug'].'.svg') }}" alt="" class="size-5" />
                            <flux:text class="font-medium">{{ $meta['label'] }}</flux:text>
                        </span>
                        <flux:heading size="lg" class="mt-2 font-ledger">
                            {{ $this->formattedAmount((float) $wallet->available_funds, $meta['decimals']) }} {{ $meta['symbol'] }}
                        </flux:heading>
                        <flux:text size="sm" variant="subtle">${{ $this->usdValue((float) $wallet->available_funds, $wallet->network) }} USD</flux:text>
                    </flux:card>
                @endforeach
            </div>
        @endif
    @endif
</div>
