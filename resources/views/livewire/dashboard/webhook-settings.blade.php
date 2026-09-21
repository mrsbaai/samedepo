<div class="py-8">
    @if ($this->uiState === 'error')
        <div class="max-w-lg mx-auto">
            <flux:callout variant="danger" icon="x-circle" heading="Couldn't load webhook settings">
                <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
                </x-slot>
            </flux:callout>
        </div>
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
        <div class="max-w-lg mx-auto space-y-6">
            <div>
                <flux:heading size="xl">Webhook Settings</flux:heading>
                <flux:subheading class="mt-2">Configure the endpoint where deposit webhooks are delivered.</flux:subheading>
            </div>

            @if ($showSetupNotice)
                <flux:callout variant="warning" icon="exclamation-triangle" heading="No webhook endpoint configured">
                    <flux:callout.text>Deposit webhooks will not be sent until you save an endpoint URL.</flux:callout.text>
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
                <flux:callout variant="warning" icon="exclamation-triangle" heading="Copy your webhook secret now">
                    <flux:callout.text>This is the only time you'll see the full secret. Use it to verify the X-Samedepo-Signature header on your endpoint.</flux:callout.text>
                    <div class="mt-3">
                        <flux:input icon="key" :value="$revealedSecret" readonly copyable class="font-ledger" />
                    </div>
                </flux:callout>
            @endif

            <flux:card>
                <div class="space-y-4">
                    <div>
                        <flux:text size="sm" class="font-medium mb-1">Endpoint URL</flux:text>
                        <flux:input.group>
                            <flux:input.group.prefix>https://</flux:input.group.prefix>
                            <flux:input wire:model="webhookUrl" placeholder="example.com/webhooks/samedepo" />
                            <flux:button variant="primary" wire:click="save">Save</flux:button>
                        </flux:input.group>
                        <flux:error name="webhookUrl" />
                        <div class="mt-3 flex items-center justify-between">
                            <flux:text size="sm" variant="subtle">Respond with any 2xx status.</flux:text>
                            <div class="flex items-center gap-2">
                                <flux:button wire:click="test" icon="paper-airplane" size="sm" variant="ghost">Test Endpoint</flux:button>
                                <flux:button href="{{ route('webhooks') }}" wire:navigate icon="bolt" size="sm" variant="ghost">View deliveries</flux:button>
                            </div>
                        </div>
                    </div>

                    @if (! $showSetupNotice)
                        <flux:separator variant="subtle" />
                        <div>
                            <flux:text size="sm" class="font-medium mb-1">Signing secret</flux:text>
                            <div class="flex items-center gap-2">
                                <flux:input
                                    type="password"
                                    :value="$this->endpoint?->getRawOriginal('secret')"
                                    readonly
                                    viewable
                                    copyable
                                    class="font-ledger w-full"
                                />
                                <flux:button wire:click="$set('showRegenerateModal', true)" icon="arrow-path" variant="ghost" size="sm">Rotate secret</flux:button>
                            </div>
                            <flux:text size="sm" variant="subtle" class="mt-2">Verify the X-Samedepo-Signature header with this secret.</flux:text>
                        </div>
                    @endif
                </div>
            </flux:card>
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
                <flux:button variant="primary" wire:click="regenerate">Rotate Secret</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
