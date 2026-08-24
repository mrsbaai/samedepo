@php $property ??= 'image'; @endphp

<div class="relative inline-block overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
    <img src="{{ $image->temporaryUrl() }}" alt="Attachment preview" class="size-14 object-cover">

    <div class="absolute top-0 right-0 p-1">
        <button type="button" wire:click="$set('{{ $property }}', null)" class="flex items-center justify-center rounded-full bg-zinc-900/50 p-0.5 hover:bg-zinc-900/70">
            <flux:icon icon="x-mark" variant="micro" class="text-white" />
        </button>
    </div>
</div>
