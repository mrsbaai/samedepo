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
                <flux:subheading class="mt-2">Configure the endpoint where credited-deposit webhooks are delivered.</flux:subheading>
            </div>

            @if ($showSetupNotice)
                <flux:callout variant="warning" icon="exclamation-triangle" heading="No webhook endpoint configured">
                    <flux:callout.text>Credited deposit webhooks will not be sent until you save an endpoint URL.</flux:callout.text>
                </flux:callout>
            @endif

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" />
            @endif

            @if ($testResult === 'success')
                <flux:callout variant="success" icon="check-circle" heading="Test delivery succeeded" />
            @elseif ($testResult === 'failure')
                <flux:callout variant="danger" icon="x-circle" heading="Test delivery failed">
                    <flux:callout.text>{{ $testError }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($revealedSecret)
                <div class="space-y-4">
                    <flux:callout variant="warning" icon="exclamation-triangle" heading="Copy your webhook secret now">
                        <flux:callout.text>This is the only time you'll see the full secret. Use it to verify the X-Samedepo-Signature header on your endpoint.</flux:callout.text>
                    </flux:callout>
                    <flux:field>
                        <flux:label>Webhook secret</flux:label>
                        <flux:input icon="key" :value="$revealedSecret" readonly copyable class="font-ledger" />
                    </flux:field>
                </div>
            @endif

            <flux:field>
                <flux:label>Endpoint URL</flux:label>
                <flux:description>Must use https://. Your endpoint should respond with any HTTP 2xx status code on a successful delivery. The response body is ignored.</flux:description>
                <flux:input.group>
                    <flux:input.group.prefix>https://</flux:input.group.prefix>
                    <flux:input wire:model="webhookUrl" placeholder="example.com/webhooks/samedepo" />
                </flux:input.group>
                <flux:error name="webhookUrl" />
            </flux:field>

            <div class="flex flex-wrap gap-4">
                <flux:button variant="primary" wire:click="save">Save Webhook Endpoint</flux:button>
                <flux:button wire:click="test" icon="paper-airplane">Test Endpoint</flux:button>
                @if (! $showSetupNotice)
                    <flux:button wire:click="$set('showRegenerateModal', true)" icon="arrow-path" variant="ghost">Regenerate secret</flux:button>
                @endif
            </div>
        </div>
    @endif

    <flux:modal wire:model.self="showRegenerateModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Regenerate webhook secret?</flux:heading>
                <flux:text class="mt-2">This invalidates the current secret immediately. Any endpoint still using the old signature will reject valid payloads until updated.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost" wire:click="cancelRegenerate">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="regenerate">Regenerate Secret</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
