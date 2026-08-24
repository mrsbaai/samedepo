<form wire:submit="changePassword" class="space-y-5">
    @if ($status)
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ $status }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:input wire:model="currentPassword" label="Current password" type="password" autocomplete="current-password" viewable required autofocus />
    <flux:input wire:model="password" label="New password" type="password" autocomplete="new-password" viewable required />
    <flux:input wire:model="passwordConfirmation" label="Confirm new password" type="password" autocomplete="new-password" viewable required />

    <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="changePassword">Change password</span>
        <span wire:loading wire:target="changePassword">Changing password…</span>
    </flux:button>
</form>
