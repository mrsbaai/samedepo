<form wire:submit="sendCode" class="space-y-5">
    @if ($status)
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ $status }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:input wire:model="email" label="Email" type="email" autocomplete="email" placeholder="email@example.com" required autofocus />

    <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="sendCode">Send reset instructions</span>
        <span wire:loading wire:target="sendCode">Sending instructions…</span>
    </flux:button>

    <flux:text class="text-center text-sm">
        First time around here? <flux:link :href="route('signup')" wire:navigate>Sign up</flux:link>
    </flux:text>
</form>
