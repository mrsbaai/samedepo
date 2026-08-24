<div class="space-y-5">
    @if ($status)
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ $status }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (! auth()->user()->hasEnabledTwoFactorAuthentication() && ! $setupInProgress)
        <flux:card class="space-y-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="sm">Two-factor authentication</flux:heading>
                    <flux:subheading class="mt-1">Protect your account with an authenticator app.</flux:subheading>
                </div>

                <flux:badge color="zinc">Disabled</flux:badge>
            </div>

            <flux:button wire:click="beginSetup" variant="primary" class="w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="beginSetup">Set up two-factor authentication</span>
                <span wire:loading wire:target="beginSetup">Preparing setup…</span>
            </flux:button>
        </flux:card>
    @endif

    @if ($setupInProgress)
        <flux:card class="space-y-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="sm">Set up authenticator</flux:heading>
                    <flux:subheading class="mt-1">Scan the QR code or enter the setup key manually.</flux:subheading>
                </div>

                <flux:badge color="amber">In progress</flux:badge>
            </div>

            {!! preg_replace(
                ['/<svg\b/', '/<rect[^>]*fill="#ffffff"[^>]*\/>/i'],
                ['<svg class="mx-auto text-accent [&_*]:fill-current"', ''],
                $qrCodeSvg
            ) !!}

            <flux:input label="Manual setup key" value="{{ $manualSecret }}" readonly copyable />

            <form wire:submit="confirmSetup" class="space-y-4">
                <flux:input
                    wire:model="code"
                    label="Verification code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    required
                    autofocus
                />

                <div class="flex gap-3">
                    <flux:button type="submit" variant="primary" class="flex-1" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="confirmSetup">Confirm and enable</span>
                        <span wire:loading wire:target="confirmSetup">Confirming…</span>
                    </flux:button>

                    <flux:button type="button" variant="ghost" wire:click="cancelSetup">
                        Cancel
                    </flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if (auth()->user()->hasEnabledTwoFactorAuthentication())
        <flux:card class="space-y-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading size="sm">Two-factor authentication</flux:heading>
                    <flux:subheading class="mt-1">Your account is protected with an authenticator app.</flux:subheading>
                </div>

                <flux:badge color="green">Enabled</flux:badge>
            </div>

            @if ($recoveryCodes)
                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-4">
                        <flux:heading size="sm">Recovery codes</flux:heading>
                        <flux:text size="sm">Save these in a secure place.</flux:text>
                    </div>

                    <div class="grid grid-cols-2 gap-2 rounded-lg bg-zinc-100 p-4 font-mono text-sm text-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                        @foreach ($recoveryCodes as $recoveryCode)
                            <span>{{ $recoveryCode }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <flux:separator />

            <div class="space-y-4">
                <flux:input
                    wire:model="currentPassword"
                    label="Current password"
                    type="password"
                    autocomplete="current-password"
                    viewable
                    required
                />

                <div class="flex flex-col gap-3">
                    <flux:button wire:click="regenerateRecoveryCodes" variant="ghost" class="w-full" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="regenerateRecoveryCodes">Generate new recovery codes</span>
                        <span wire:loading wire:target="regenerateRecoveryCodes">Generating…</span>
                    </flux:button>

                    <flux:button wire:click="disable" variant="danger" class="w-full" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="disable">Disable two-factor authentication</span>
                        <span wire:loading wire:target="disable">Disabling…</span>
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif
</div>
