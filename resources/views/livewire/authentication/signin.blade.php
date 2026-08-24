<form wire:submit="signin" class="space-y-5">
    <x-authentication.social-providers :providers="$socialProviders" />

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle">
            {{ $error }}
        </flux:callout>
    @endif

    <flux:input wire:model="email" label="Email" type="email" autocomplete="email" placeholder="email@example.com" required autofocus />

    <flux:field>
        <div class="mb-3 flex justify-between">
            <flux:label>Password</flux:label>
            <flux:link :href="route('password.request')" variant="subtle" class="text-sm" wire:navigate>Forgot password?</flux:link>
        </div>

        <flux:input wire:model="password" type="password" autocomplete="current-password" placeholder="Your password" viewable required />
    </flux:field>

    <flux:checkbox wire:model="remember" label="Remember me for {{ $rememberDays }} days" />

    <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="signin">Sign in</span>
        <span wire:loading wire:target="signin">Signing in…</span>
    </flux:button>

    <flux:text class="text-center text-sm">
        First time around here? <flux:link :href="route('signup')" wire:navigate>Sign up for free</flux:link>
    </flux:text>
</form>
