@props(['addresses'])

@if (empty($addresses))
    <flux:text size="sm">No deposit addresses found for this customer.</flux:text>
@else
    <div class="space-y-2">
        @foreach ($addresses as $addr)
            <div class="flex items-center gap-3" wire:key="addr-{{ $addr['networkSlug'] }}">
                <img src="{{ asset('crypto/'.$addr['networkSlug'].'.svg') }}" alt="" class="size-4 shrink-0" />

                <flux:text size="sm" class="w-28 shrink-0">{{ $addr['networkLabel'] }}</flux:text>

                <code class="text-xs truncate flex-1 text-zinc-600 dark:text-zinc-400 font-ledger">{{ $addr['address'] }}</code>

                <flux:tooltip content="Copy address">
                    <flux:button variant="ghost" size="sm" icon="clipboard-document" onclick="navigator.clipboard.writeText('{{ $addr['address'] }}')" />
                </flux:tooltip>

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
@endif
