@props([
    'iconVariant' => 'mini',
    'size' => null,
])

<button
    type="button"
    {{ $attributes->class('-me-1 flex items-center justify-center text-zinc-400 transition hover:text-zinc-700 dark:text-zinc-500 dark:hover:text-zinc-200') }}
    x-data="fluxInputViewable"
    x-on:click="toggle()"
    x-bind:data-viewable-open="open"
    aria-label="{{ __('Toggle password visibility') }}"
>
    <flux:icon.eye-slash :variant="$iconVariant" class="hidden size-4 [[data-viewable-open]>&]:block" />
    <flux:icon.eye :variant="$iconVariant" class="block size-4 [[data-viewable-open]>&]:hidden" />
</button>
