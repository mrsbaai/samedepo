<div class="space-y-5">
    @if ($status)
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ $status }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-circle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex items-center justify-between gap-4">
        <flux:heading size="lg">Active sessions</flux:heading>
        <flux:button wire:click="revokeAllOtherSessions" variant="danger" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="revokeAllOtherSessions">Sign out all other sessions</span>
            <span wire:loading wire:target="revokeAllOtherSessions">Signing out…</span>
        </flux:button>
    </div>

    @forelse ($sessions as $session)
        <flux:card>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading>{{ $session->isCurrent ? 'Current session' : 'Active session' }}</flux:heading>
                    <flux:text>{{ $session->userAgent ?: 'Unknown browser or device' }}</flux:text>
                    <flux:text class="text-zinc-400">{{ $session->ipAddress ?: 'Unknown IP address' }}</flux:text>
                </div>
                @if ($session->isCurrent)
                    <flux:badge color="green">Current</flux:badge>
                @else
                    <flux:button wire:click="revoke('{{ $session->id }}')" variant="ghost" size="sm" wire:loading.attr="disabled">
                        Revoke
                    </flux:button>
                @endif
            </div>
        </flux:card>
    @empty
        <flux:callout variant="secondary" icon="information-circle">
            <flux:callout.text>No active sessions were found.</flux:callout.text>
        </flux:callout>
    @endforelse
</div>
