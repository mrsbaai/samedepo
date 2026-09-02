@props(['deposits'])

<flux:table :paginate="$deposits" pagination:scroll-to>
    <flux:table.columns>
        <flux:table.column>Date</flux:table.column>
        <flux:table.column>Network</flux:table.column>
        <flux:table.column align="end">Gross</flux:table.column>
        <flux:table.column align="end" class="max-lg:hidden">Fee</flux:table.column>
        <flux:table.column align="end" class="max-lg:hidden">Credited</flux:table.column>
        <flux:table.column>Status</flux:table.column>
        <flux:table.column>Tx</flux:table.column>
    </flux:table.columns>

    <flux:table.rows>
        @forelse ($deposits as $d)
            <flux:table.row :key="$d['id']">
                <flux:table.cell class="whitespace-nowrap">{{ $d['at']->format('M j, H:i') }}</flux:table.cell>

                <flux:table.cell>
                    <span class="flex items-center gap-2">
                        <img src="{{ asset('crypto/'.$d['networkSlug'].'.svg') }}" alt="" class="size-4" />
                        <span class="max-md:hidden">{{ $d['networkLabel'] }}</span>
                    </span>
                </flux:table.cell>

                <flux:table.cell align="end" variant="strong" class="font-ledger tabular-nums">
                    {{ $d['gross'] }} {{ $d['symbol'] }}
                </flux:table.cell>

                <flux:table.cell align="end" class="font-ledger tabular-nums max-lg:hidden">
                    {{ $d['fee'] ?? '—' }}
                </flux:table.cell>

                <flux:table.cell align="end" class="font-ledger tabular-nums max-lg:hidden">
                    {{ $d['credited'] ?? '—' }}
                </flux:table.cell>

                <flux:table.cell class="py-0">
                    <flux:badge size="sm" color="{{ \App\Support\DepositRow::STATUS_COLORS[$d['status']] ?? 'zinc' }}">
                        {{ ucfirst($d['status']) }}
                    </flux:badge>
                </flux:table.cell>

                <flux:table.cell class="py-0 font-mono text-xs">
                    @if ($d['explorerUrl'])
                        <flux:link href="{{ $d['explorerUrl'] }}" target="_blank" rel="noopener">
                            {{ substr($d['txHash'], 0, 6) }}…{{ substr($d['txHash'], -4) }}
                        </flux:link>
                    @else
                        —
                    @endif
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="7">
                    <flux:text size="sm">No deposits yet.</flux:text>
                </flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
