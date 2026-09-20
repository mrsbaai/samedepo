<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load webhook settings">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="max-w-3xl">
            <flux:skeleton class="h-8 w-48 mb-4" />
            <div class="space-y-3">
                <flux:skeleton class="h-10 w-full" />
                <flux:skeleton class="h-5 w-40" />
                <flux:skeleton class="h-5 w-48" />
            </div>
        </div>
    @else
        <div class="space-y-8 max-w-3xl">
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

            <div>
                <flux:heading size="sm" class="mb-3">Endpoint</flux:heading>
                <flux:field>
                    <flux:label>Endpoint URL</flux:label>
                    <flux:description>Must use https://. Your endpoint should respond with any HTTP 2xx status code on a successful delivery.</flux:description>
                    <flux:input.group>
                        <flux:input.group.prefix>https://</flux:input.group.prefix>
                        <flux:input wire:model="webhookUrl" placeholder="example.com/webhooks/samedepo" />
                        <flux:button variant="primary" wire:click="save">Save Webhook Endpoint</flux:button>
                    </flux:input.group>
                    <flux:error name="webhookUrl" />
                </flux:field>
                <div class="mt-3">
                    <flux:button wire:click="test" icon="paper-airplane" size="sm" variant="ghost">Test Endpoint</flux:button>
                </div>
            </div>

            @if (! $showSetupNotice)
                <div>
                    <flux:heading size="sm" class="mb-3">Signing secret</flux:heading>
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:input
                            type="password"
                            :value="$this->endpoint?->getRawOriginal('secret')"
                            readonly
                            viewable
                            copyable
                            class="font-ledger max-w-md"
                        />
                        <flux:button wire:click="$set('showRegenerateModal', true)" icon="arrow-path" variant="ghost" size="sm">Rotate secret</flux:button>
                    </div>
                    <flux:text size="sm" variant="subtle" class="mt-2">Verify the X-Samedepo-Signature header with this secret.</flux:text>
                </div>
            @endif

            <div>
                <flux:heading size="sm" class="mb-3">Recent deliveries</flux:heading>
                @if ($this->deliveries->isEmpty())
                    <flux:text size="sm" variant="subtle">No deliveries recorded yet.</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Time</flux:table.column>
                            <flux:table.column>Event</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Code</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->deliveries as $delivery)
                                <flux:table.row wire:key="delivery-{{ $delivery->id }}">
                                    <flux:table.cell class="whitespace-nowrap">{{ \App\Support\Dates::humanFlat($delivery->created_at) }}</flux:table.cell>
                                    <flux:table.cell class="font-ledger">{{ $delivery->event }}</flux:table.cell>
                                    <flux:table.cell class="py-0">
                                        <flux:badge size="sm" color="{{ $delivery->status === 'delivered' ? 'green' : 'red' }}">{{ ucfirst($delivery->status) }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="font-ledger">{{ $delivery->response_code ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="py-0">
                                        @if ($delivery->status === 'failed')
                                            <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="redeliver({{ $delivery->id }})">Retry</flux:button>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
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
                <flux:button variant="primary" wire:click="regenerate">Rotate Secret</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
