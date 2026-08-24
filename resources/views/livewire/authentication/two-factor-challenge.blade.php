<div class="space-y-5">
    @if ($error)
        <flux:callout variant="danger" icon="exclamation-circle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (! $showRecoveryCode)
        <form wire:submit="verify" class="space-y-5">
            <flux:otp
                wire:model="code"
                length="6"
                submit="auto"
                label="Authenticator code"
                label:sr-only
                class="mx-auto"
            />
        </form>

        <flux:button type="button" wire:click="showRecoveryCodeForm" variant="ghost" size="sm" class="w-full">
            Use recovery code
        </flux:button>
    @else
        <form wire:submit="verifyRecoveryCode" class="space-y-5">
            <flux:input wire:model="recoveryCode" label="Recovery code" autocomplete="one-time-code" required />

            <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="verifyRecoveryCode">Use recovery code</span>
                <span wire:loading wire:target="verifyRecoveryCode">Verifying…</span>
            </flux:button>
        </form>

        <flux:button type="button" wire:click="hideRecoveryCodeForm" variant="ghost" size="sm" class="w-full">
            Back to code
        </flux:button>
    @endif
</div>
