<form wire:submit="signup" class="space-y-5">
    <x-authentication.social-providers :providers="$socialProviders" />

    <flux:input wire:model="email" label="Email" type="email" autocomplete="email" placeholder="email@example.com" required autofocus />
    <flux:input wire:model="password" label="Password" type="password" autocomplete="new-password" placeholder="Your password" viewable required />
    <flux:input wire:model="passwordConfirmation" label="Confirm password" type="password" autocomplete="new-password" placeholder="Confirm your password" viewable required />

    <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="signup">Sign up</span>
        <span wire:loading wire:target="signup">Signing up…</span>
    </flux:button>

    <flux:text class="text-center text-sm">
        Already have an account? <flux:link :href="route('signin')" wire:navigate>Sign in</flux:link>
    </flux:text>
</form>
