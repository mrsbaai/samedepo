<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load webhook settings">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="max-w-lg mx-auto">
            <flux:skeleton class="h-8 w-48 mb-4" />
            <div class="space-y-3">
                <flux:skeleton class="h-10 w-full" />
                <flux:skeleton class="h-5 w-40" />
                <flux:skeleton class="h-5 w-48" />
            </div>
        </div>
    @else
        <div class="space-y-8 max-w-lg">
            <div>
                <flux:heading size="xl">Webhook Settings</flux:heading>
                <flux:subheading class="mt-2">Configure the webhook endpoint for deposit and withdrawal events.</flux:subheading>
            </div>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" />
            @endif

            <flux:field>
                <flux:label>Endpoint URL</flux:label>
                <flux:description>Must use https://</flux:description>
                <flux:input.group>
                    <flux:input.group.prefix>https://</flux:input.group.prefix>
                    <flux:input wire:model="webhookUrl" placeholder="example.com/webhooks/samedepo" />
                </flux:input.group>
                <flux:error name="webhookUrl" />
            </flux:field>

            <flux:fieldset>
                <flux:legend>Enabled events</flux:legend>
                <div class="space-y-4">
                    <flux:switch wire:model="eventCreditedDeposit" label="Credited deposit" description="Fires when a confirmed deposit is credited to a customer balance." />
                    <flux:separator variant="subtle" />
                    <flux:switch wire:model="eventWithdrawalStatus" label="Withdrawal status" description="Fires when a withdrawal request is approved, denied, or sent." />
                </div>
            </flux:fieldset>

            <flux:button variant="primary" wire:click="save">Save Webhook Endpoint</flux:button>
        </div>
    @endif
</div>
