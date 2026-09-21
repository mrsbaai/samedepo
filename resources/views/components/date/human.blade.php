@props(['at'])

@php
$carbon = $at instanceof \Carbon\CarbonInterface ? $at : ($at ? \Carbon\Carbon::parse($at) : null);
@endphp

@if ($carbon)
    <flux:tooltip content="{{ $carbon->copy()->utc()->format('M j, H:i') }}">
        <span>{{ $carbon->diffForHumans() }}</span>
    </flux:tooltip>
@else
    <span>&mdash;</span>
@endif
