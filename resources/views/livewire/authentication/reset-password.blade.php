<form wire:submit="resetPassword" class="space-y-5">
    @if ($error)
        <flux:callout variant="danger" icon="exclamation-circle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:input wire:model="password" label="Password" type="password" autocomplete="new-password" placeholder="Your password" viewable required autofocus />
    <flux:input wire:model="passwordConfirmation" label="Confirm password" type="password" autocomplete="new-password" placeholder="Confirm your password" viewable required />

    <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="resetPassword">Reset password</span>
        <span wire:loading wire:target="resetPassword">Resetting password…</span>
    </flux:button>

    <flux:text class="text-center text-sm">
        Not working for some reason? <flux:link :href="route('password.request')" wire:navigate>Request a new reset link</flux:link>
    </flux:text>
</form>
