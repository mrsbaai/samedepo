<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">Withdrawals</flux:heading>
        <flux:subheading class="mt-2">Every withdrawal request with its fees, status, and transaction link.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load withdrawals">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Requested</flux:table.column>
                <flux:table.column>Network</flux:table.column>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-28" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-14" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        @if ($successMessage)
            <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" class="mb-6" />
        @endif

        @if ($this->withdrawals->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="arrow-up-tray" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">No withdrawals yet. Request one from a balance card on your dashboard.</flux:text>
            </div>
        @else
            <flux:table :paginate="$this->withdrawals" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column>Requested</flux:table.column>
                    <flux:table.column class="max-md:hidden">Network</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column class="max-lg:hidden">Network fee</flux:table.column>
                    <flux:table.column class="max-lg:hidden">Consolidation</flux:table.column>
                    <flux:table.column class="max-md:hidden">Sent</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column class="max-md:hidden">Tx</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->withdrawals as $w)
                        <flux:table.row wire:key="withdrawal-{{ $w['id'] }}">
                            <flux:table.cell class="whitespace-nowrap">{{ $w['requested'] }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                <span class="flex items-center gap-1.5">
                                    <x-crypto-icon :icon="$w['icon']" :badge="$w['badge']" class="size-4" />
                                    {{ $w['networkLabel'] }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell variant="strong" class="font-ledger whitespace-nowrap">
                                {{ $w['amount'] }} {{ $w['symbol'] }}
                            </flux:table.cell>
                            <flux:table.cell class="max-lg:hidden font-ledger whitespace-nowrap">
                                @if ($w['networkFee'] !== null)
                                    −{{ $w['networkFee'] }} {{ $w['symbol'] }}
                                @else
                                    &mdash;
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-lg:hidden font-ledger whitespace-nowrap">
                                @if ($w['consolidationFee'] !== null)
                                    −{{ $w['consolidationFee'] }} {{ $w['symbol'] }}
                                @else
                                    &mdash;
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden font-ledger whitespace-nowrap">
                                @if ($w['sent'] !== null)
                                    {{ $w['sent'] }} {{ $w['symbol'] }}
                                @else
                                    &mdash;
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                <flux:badge size="sm" color="{{ $w['statusColor'] }}">{{ $w['statusLabel'] }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden py-0">
                                @if ($w['explorerUrl'])
                                    <flux:link href="{{ $w['explorerUrl'] }}" target="_blank" class="font-ledger">{{ $w['txShort'] }}</flux:link>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                @if ($w['canCancel'])
                                    <flux:button variant="ghost" size="sm" wire:click="confirmCancel({{ $w['id'] }})">Cancel</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif

    <flux:modal wire:model.self="showCancelModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Cancel withdrawal?</flux:heading>
                <flux:text class="mt-2">The reserved balance is returned to your available balance. This can't be undone.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Keep withdrawal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="cancelWithdrawal">Cancel withdrawal</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
