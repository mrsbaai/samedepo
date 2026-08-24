<div class="mx-auto max-w-2xl">
    <flux:heading size="xl" level="1">New ticket</flux:heading>
    <flux:subheading class="mt-2">What can we help you with?</flux:subheading>
    <flux:callout icon="information-circle" variant="secondary" class="mt-4">
        <flux:callout.text>Please include as much detail as you can. Screenshots are always helpful. We’ll do our best to reply within 24 hours.</flux:callout.text>
    </flux:callout>

    <div class="mt-6 space-y-4">
        <flux:input wire:model="subject" label="Subject" placeholder="What do you need help with?" />

        <form wire:submit="create">
            <flux:composer class="dark" wire:model="body" label="Message" label:sr-only placeholder="Describe the issue…">
                @if ($image)
                    <x-slot name="header">
                        @include('livewire.support.partials.image-attachment-preview')
                    </x-slot>
                @endif

                <x-slot name="actionsLeading">
                    <flux:file-upload wire:model="image" accept="image/*">
                        <flux:tooltip content="Attach an image" class="contents">
                            <flux:button type="button" size="sm" variant="subtle" icon="paper-clip" />
                        </flux:tooltip>
                    </flux:file-upload>
                </x-slot>

                <x-slot name="actionsTrailing">
                    <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">Submit ticket</flux:button>
                </x-slot>
            </flux:composer>
        </form>
    </div>
</div>
