<x-layouts.public :title="$page->title">
    <div class="mt-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $page->title }}</flux:heading>

        @if ($page->updated_at)
            <flux:badge variant="subtle" color="zinc" size="sm">
                Updated {{ $page->updated_at->format('M d, Y') }}
            </flux:badge>
        @endif
    </div>

    <flux:separator class="my-6" />

    <flux:card>
        <div class="prose prose-zinc max-w-none dark:prose-invert">
            {!! $page->content !!}
        </div>
    </flux:card>
</x-layouts.public>
