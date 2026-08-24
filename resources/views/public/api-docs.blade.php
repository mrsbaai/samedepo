<x-layouts.public :title="'API Documentation'" :description="'API documentation for the samedepo integration endpoints.'">
    <section class="py-24 max-w-2xl mx-auto text-center">
        <flux:badge color="zinc" size="sm" class="mb-6">In progress</flux:badge>
        <flux:heading size="xl" level="1">API Documentation</flux:heading>
        <flux:text size="lg" class="mt-4 text-zinc-400">
            Full endpoint reference, authentication guidance, and webhook payload examples are on the way.
        </flux:text>
        <div class="mt-8">
            <flux:button href="{{ url('/') }}" variant="primary" wire:navigate>Back to home</flux:button>
        </div>
    </section>
</x-layouts.public>
