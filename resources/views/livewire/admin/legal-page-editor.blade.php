<div class="flex flex-col gap-4">
    <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl">{{ $page->title }}</flux:heading>
            <flux:subheading>Shown publicly at {{ url('/'.$page->slug) }}</flux:subheading>
        </div>

        <flux:button variant="primary" size="sm" icon="check" wire:click="save">Save</flux:button>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:input wire:model="title" label="Title" />

    <flux:editor class="dark" wire:model="content" label="Content" />
</div>
