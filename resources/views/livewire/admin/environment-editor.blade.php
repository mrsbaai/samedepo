<div class="flex flex-col gap-4">
    <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl">Environment</flux:heading>
            <flux:subheading>Edit the raw {{ '.env' }} file. Saving clears and rebuilds the configuration cache.</flux:subheading>
        </div>

        <div class="flex shrink-0 gap-2" x-data="{ dirty: false }" x-init="$watch('$wire.content', (value) => dirty = value !== $wire.savedContent)" x-show="dirty" x-cloak>
            <flux:button variant="ghost" size="sm" wire:click="cancel">Cancel</flux:button>
            <flux:button variant="primary" size="sm" icon="check" wire:click="save">Save &amp; apply</flux:button>
        </div>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @error('content')
        <flux:callout variant="danger" icon="exclamation-circle">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <flux:textarea
        wire:model="content"
        rows="28"
        resize="vertical"
        class="font-mono text-sm"
        :invalid="$errors->has('content')"
    />
</div>
