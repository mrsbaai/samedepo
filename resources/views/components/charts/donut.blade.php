@props([
    'segments' => [],
    'total' => '',
])

@php
    $radius = 15.9155;
    $cumulative = 0.0;
@endphp

<div {{ $attributes->class('flex flex-wrap items-center gap-6') }}>
    <div class="relative size-40 shrink-0">
        <svg viewBox="0 0 42 42" class="size-full" role="img" aria-label="Income share">
            <circle cx="21" cy="21" r="{{ $radius }}" fill="none" stroke-width="6" class="stroke-zinc-800" />
            @foreach ($segments as $segment)
                <circle
                    cx="21" cy="21" r="{{ $radius }}" fill="none"
                    stroke="currentColor" stroke-width="6" stroke-linecap="butt"
                    class="text-{{ $segment['color'] }}"
                    stroke-dasharray="{{ $segment['value'] }} {{ max(0, 100 - $segment['value']) }}"
                    stroke-dashoffset="{{ -$cumulative }}"
                    transform="rotate(-90 21 21)"
                />
                @php($cumulative += (float) $segment['value'])
            @endforeach
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
            <flux:heading size="lg" class="font-ledger tabular-nums">{{ $total }}</flux:heading>
            <flux:text size="sm" variant="subtle">Income</flux:text>
        </div>
    </div>

    <div class="min-w-0 flex-1">
        @foreach ($segments as $segment)
            <flux:chart.legend class="w-full p-0!">
                <flux:chart.legend.indicator class="bg-{{ $segment['color'] }}" />
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $segment['label'] }}</div>
                <div class="ms-auto text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($segment['value'], 1) }}%</div>
            </flux:chart.legend>
        @endforeach
    </div>
</div>
