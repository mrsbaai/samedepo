<form wire:submit="requestDeletion" class="space-y-5">
    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.heading>Deleting your account</flux:callout.heading>
        <flux:callout.text>
            Your account will be deactivated immediately and all active sessions will be signed out.
            You have <strong>{{ config('authentication.deletion.grace_period_days') }} days</strong> to recover your account using the link we email you.
            After that, your data will be permanently erased.
        </flux:callout.text>
    </flux:callout>

    <flux:input wire:model="currentPassword" label="Current password" type="password" autocomplete="current-password" viewable required autofocus />

    <flux:checkbox wire:model="confirmDeletion" label="I understand that my account will be deactivated and may be permanently deleted after the grace period." required />

    <flux:button type="submit" variant="danger" class="w-full" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="requestDeletion">Request account deletion</span>
        <span wire:loading wire:target="requestDeletion">Requesting deletion…</span>
    </flux:button>
</form>
