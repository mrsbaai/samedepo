@props(['value', 'url' => null, 'label' => 'tx hash'])

<span class="inline-flex items-center gap-1" x-data="{ copied: false }">
    <flux:tooltip content="Copy {{ $label }}">
        <flux:button variant="ghost" size="sm" square x-on:click="navigator.clipboard.writeText({{ json_encode($value) }}); copied = true; setTimeout(() => copied = false, 1500)">
            <flux:icon.clipboard-document variant="mini" x-show="! copied" />
            <flux:icon.clipboard-document-check variant="mini" x-show="copied" x-cloak class="text-green-500" />
        </flux:button>
    </flux:tooltip>
    @if ($url)
        <flux:tooltip content="View on explorer">
            <flux:button variant="ghost" size="sm" icon="arrow-top-right-on-square" href="{{ $url }}" target="_blank" rel="noopener" />
        </flux:tooltip>
    @endif
</span>
