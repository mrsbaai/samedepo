<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load withdrawal queue">
            <flux:callout.text>Couldn't load withdrawal queue. Please try again.</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-8 w-48 mb-6" />
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Owner</flux:table.column>
                <flux:table.column>Network</flux:table.column>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column class="max-md:hidden">Requested</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 3) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-32" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-8" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <flux:heading size="xl" class="mb-6">Pending Withdrawals</flux:heading>

        @if ($withdrawals->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="check-circle" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">Nothing pending.</flux:text>
                <flux:text size="sm" variant="subtle">Every withdrawal request has been reviewed.</flux:text>
            </div>
        @else
            <flux:table :paginate="$withdrawals">
                <flux:table.columns>
                    <flux:table.column>Owner</flux:table.column>
                    <flux:table.column>Network</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column class="max-md:hidden">Requested</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($withdrawals as $withdrawal)
                        @php($meta = $this->networkMeta($withdrawal->network))
                        <flux:table.row wire:key="wq-{{ $withdrawal->id }}">
                            <flux:table.cell variant="strong">{{ $withdrawal->user->email }}</flux:table.cell>
                            <flux:table.cell>
                                <span class="flex items-center gap-1.5">
                                    <img src="{{ asset('crypto/'.$meta['slug'].'.svg') }}" alt="" class="size-4" />
                                    <span class="max-md:hidden">{{ $meta['label'] }}</span>
                                </span>
                            </flux:table.cell>
                            <flux:table.cell class="font-ledger">
                                {{ $this->formattedAmount((float) $withdrawal->gross_amount, $meta['decimals']) }} {{ $meta['symbol'] }}
                                <flux:text size="sm" variant="subtle">${{ $this->usdValue((float) $withdrawal->gross_amount, $withdrawal->network) }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden whitespace-nowrap">
                                <flux:tooltip content="{{ $withdrawal->created_at->format('M j, Y H:i') }} UTC">
                                    <span>{{ $withdrawal->created_at->diffForHumans() }}</span>
                                </flux:tooltip>
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                <flux:button variant="ghost" size="sm" icon="chevron-right" href="{{ route('admin.withdrawals.show', $withdrawal) }}" wire:navigate />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</div>
