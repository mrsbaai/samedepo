<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">Dashboard</flux:heading>
        <flux:subheading class="mt-2">Your balances, estimated USD values, and income over time.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load dashboard data">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="flex flex-wrap gap-x-10 gap-y-5">
            @foreach (range(1, 3) as $i)
                <div class="min-w-36">
                    <flux:skeleton class="h-5 w-24" />
                    <flux:skeleton class="mt-2 h-8 w-28" />
                    <flux:skeleton class="mt-2 h-4 w-24" />
                </div>
            @endforeach
        </div>
        <flux:skeleton class="mt-10 h-72 w-full" />
    @else
        <div class="flex flex-wrap items-start gap-x-10 gap-y-5">
            @foreach (array_filter($this->stats, fn ($stat) => ! $stat['zero']) as $stat)
                <div class="min-w-36">
                    <div class="flex min-w-0 items-center gap-2">
                        <x-crypto-icon :icon="$stat['icon']" :badge="$stat['badge']" class="size-5" />
                        <flux:text class="truncate font-medium">{{ $stat['label'] }}</flux:text>
                    </div>
                    <flux:heading size="xl" class="mt-2 font-ledger">{{ $stat['value'] }}</flux:heading>
                    <flux:text size="sm" variant="subtle" class="mt-0.5 font-ledger">{{ $stat['amount'] }}</flux:text>
                    <flux:button size="xs" variant="ghost" href="{{ route('withdraw', ['network' => $stat['network']]) }}" wire:navigate class="mt-1 -ms-2">Withdraw</flux:button>
                </div>
            @endforeach
        </div>

        @php($zeroStats = array_filter($this->stats, fn ($stat) => $stat['zero']))
        @if ($zeroStats !== [])
            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1">
                @foreach ($zeroStats as $stat)
                    <flux:text size="sm" variant="subtle">{{ $stat['label'] }} <span class="font-ledger">{{ $stat['value'] }}</span></flux:text>
                @endforeach
            </div>
        @endif

        <livewire:dashboard.income-charts :owner-id="auth()->id()" />
    @endif
</div>
