<x-layouts.public :title="$page->title">
    <flux:heading size="xl" level="1">{{ $page->title }}</flux:heading>

    <flux:card class="mt-6">
        <div class="prose prose-zinc dark:prose-invert">
            {!! $page->content !!}
        </div>
    </flux:card>
</x-layouts.public>
