<flux:button
    icon="{{ $appearance === 'dark' ? 'moon' : 'sun' }}"
    variant="ghost"
    size="sm"
    square
    class="me-2"
    wire:click="toggle"
    x-on:click="$flux.appearance = $wire.appearance === 'dark' ? 'light' : 'dark'"
    aria-label="Toggle appearance"
/>
