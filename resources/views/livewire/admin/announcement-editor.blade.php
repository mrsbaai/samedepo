<div class="flex flex-col gap-4">
    <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl">Announcement</flux:heading>
            <flux:subheading>Shown to every signed-in user until you remove it.</flux:subheading>
        </div>

        <div class="flex shrink-0 gap-2">
            <flux:button variant="ghost" size="sm" icon="trash" wire:click="remove" wire:confirm="Remove the announcement?">Remove</flux:button>
            <flux:button variant="primary" size="sm" icon="check" wire:click="save">Save</flux:button>
        </div>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:editor class="dark" wire:model="content" label="Announcement" />
</div>
