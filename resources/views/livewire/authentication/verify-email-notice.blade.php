<div class="space-y-5">
    @if (session('status'))
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-circle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($status)
        <flux:callout variant="secondary" icon="information-circle">
            <flux:callout.text>{{ $status }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:text class="text-center text-zinc-400">Enter the 6-digit code we sent to {{ auth()->user()->email }}.</flux:text>

    <form wire:submit="verify" class="space-y-6">
        <flux:otp
            wire:model="code"
            length="6"
            submit="auto"
            label="Verification code"
            label:sr-only
            class="mx-auto"
        />
    </form>

    <flux:button type="button" wire:click="resend" variant="ghost" class="w-full" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="resend">Resend code</span>
        <span wire:loading wire:target="resend">Sending…</span>
    </flux:button>
</div>
