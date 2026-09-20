@props(['icon' => null, 'badge' => null])

<span {{ $attributes->merge(['class' => 'relative inline-flex shrink-0']) }}>
    @if ($icon)
        <img src="{{ \App\Support\CryptoIcon::url($icon) }}" alt="" class="block size-full" />
        @if ($badge)
            <img src="{{ \App\Support\CryptoIcon::url($badge) }}" alt="" class="absolute -bottom-0.5 -right-0.5 size-[55%] rounded-full bg-white ring-1 ring-white dark:bg-zinc-900 dark:ring-zinc-900" />
        @endif
    @else
        <flux:icon name="question-mark-circle" variant="outline" class="size-full text-zinc-400" />
    @endif
</span>
