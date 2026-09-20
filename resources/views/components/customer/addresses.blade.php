@props(['addresses'])

@if (empty($addresses))
    <flux:text size="sm">No deposit addresses found for this customer.</flux:text>
@else
    <div class="space-y-3">
        @foreach (collect($addresses)->groupBy('address') as $address => $rows)
            <flux:card class="py-3 px-4 space-y-2">
                <div class="flex items-center gap-3">
                    <code class="text-xs truncate flex-1 text-zinc-600 dark:text-zinc-400 font-ledger">{{ $address }}</code>

                    <flux:tooltip content="Copy address">
                        <flux:button variant="ghost" size="sm" icon="clipboard-document" onclick="navigator.clipboard.writeText('{{ $address }}')" />
                    </flux:tooltip>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    @foreach ($rows as $addr)
                        <div class="flex items-center gap-2" wire:key="addr-{{ $addr['networkSlug'] }}">
                            <x-crypto-icon :icon="$addr['icon']" :badge="$addr['badge']" class="size-4" />
                            <flux:text size="sm">{{ $addr['networkLabel'] }}</flux:text>
                            <flux:badge size="sm" color="zinc">{{ $addr['chainLabel'] }}</flux:badge>
                            @if ($explorerUrl = \App\Support\ExplorerUrl::for('address', $addr['network'], $addr['address']))
                                <flux:tooltip content="View on explorer">
                                    <flux:link href="{{ $explorerUrl }}" target="_blank" rel="noopener" class="shrink-0">
                                        <flux:icon name="arrow-top-right-on-square" class="size-4" />
                                    </flux:link>
                                </flux:tooltip>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endforeach
    </div>
@endif
