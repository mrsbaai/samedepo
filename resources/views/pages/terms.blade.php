<x-layouts.public :title="$page->title">
    <flux:heading size="xl" level="1">{{ $page->title }}</flux:heading>

    <div class="prose prose-zinc mt-6 dark:prose-invert">
        {!! $page->content !!}
    </div>
</x-layouts.public>
