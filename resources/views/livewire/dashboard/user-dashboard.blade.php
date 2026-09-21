<div class="space-y-10">
    <div>
        <flux:heading size="xl">Dashboard</flux:heading>
        <flux:subheading class="mt-1">Balances and income across your enabled networks.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load dashboard data">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <section>
            <flux:heading size="lg">Balances</flux:heading>
            <div class="mt-4 grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-zinc-800 bg-zinc-800 sm:grid-cols-2 xl:grid-cols-3">
                @foreach (range(1, 3) as $i)
                    <div class="bg-zinc-900 p-5">
                        <flux:skeleton class="h-5 w-32" />
                        <flux:skeleton class="mt-3 h-8 w-28" />
                        <flux:skeleton class="mt-2 h-4 w-24" />
                    </div>
                @endforeach
            </div>
        </section>
        <flux:skeleton class="h-72 w-full" />
    @else
        @php
            $fundedStats = array_filter($this->stats, fn ($stat) => ! $stat['zero']);
            $zeroStats = array_filter($this->stats, fn ($stat) => $stat['zero']);
        @endphp

        <section>
            <flux:heading size="lg">Balances</flux:heading>

            @if ($fundedStats === [])
                <flux:callout icon="wallet" class="mt-4">
                    <flux:callout.heading>No balances yet</flux:callout.heading>
                    <flux:callout.text>Credited deposits appear here once they confirm.</flux:callout.text>
                </flux:callout>
            @else
                <div class="mt-4 grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-zinc-800 bg-zinc-800 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($fundedStats as $stat)
                        <div class="bg-zinc-900 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-2">
                                    <x-crypto-icon :icon="$stat['icon']" :badge="$stat['badge']" class="size-5" />
                                    <flux:text size="sm" class="truncate font-medium">{{ $stat['label'] }}</flux:text>
                                </div>
                                <flux:button size="xs" variant="ghost" icon:trailing="arrow-up-right" href="{{ route('withdraw', ['network' => $stat['network']]) }}" wire:navigate>Withdraw</flux:button>
                            </div>
                            <flux:heading size="xl" class="mt-3 font-ledger">{{ $stat['value'] }}</flux:heading>
                            <flux:text size="sm" variant="subtle" class="mt-0.5 font-ledger">{{ $stat['amount'] }}</flux:text>
                        </div>
                    @endforeach
                </div>

                @if ($zeroStats !== [])
                    <flux:text size="sm" variant="subtle" class="mt-3">No balance yet: {{ implode(' · ', array_column($zeroStats, 'label')) }}</flux:text>
                @endif
            @endif
        </section>

        <livewire:dashboard.income-charts :owner-id="auth()->id()" />
    @endif
</div>
