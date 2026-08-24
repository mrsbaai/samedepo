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

    @if ($pendingRequest)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>Pending email change</flux:callout.heading>
            <flux:callout.text>
                A verification link was sent to your new email address. Expires {{ $pendingRequest->expires_at->diffForHumans() }}.
            </flux:callout.text>
        </flux:callout>

        <flux:button wire:click="cancelPendingRequest" variant="ghost" class="w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="cancelPendingRequest">Cancel request</span>
            <span wire:loading wire:target="cancelPendingRequest">Cancelling…</span>
        </flux:button>
    @endif

    <form wire:submit="requestChange" class="space-y-5">
        <flux:input wire:model="currentPassword" label="Current password" type="password" autocomplete="current-password" viewable required autofocus />
        <flux:input wire:model="email" label="New email address" type="email" autocomplete="email" required />

        <flux:callout variant="secondary" icon="information-circle">
            <flux:callout.text>We will send a verification link to your new email. Your email will not change until you verify the new address.</flux:callout.text>
        </flux:callout>

        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="requestChange">Request email change</span>
            <span wire:loading wire:target="requestChange">Sending verification…</span>
        </flux:button>
    </form>
</div>
