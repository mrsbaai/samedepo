@props([
    'iconVariant' => 'mini',
    'size' => null,
])

<button
    type="button"
    {{ $attributes->class('-me-1 flex items-center justify-center text-zinc-400 transition hover:text-zinc-700 dark:text-zinc-500 dark:hover:text-zinc-200') }}
    x-data="fluxInputCopyable"
    x-on:click="copy()"
    x-bind:data-copyable-copied="copied"
    aria-label="{{ __('Copy to clipboard') }}"
>
    <flux:icon.clipboard-document-check :variant="$iconVariant" class="hidden size-4 [[data-copyable-copied]>&]:block" />
    <flux:icon.clipboard-document :variant="$iconVariant" class="block size-4 [[data-copyable-copied]>&]:hidden" />
</button>
