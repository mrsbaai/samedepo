<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">Webhooks</flux:heading>
        <flux:subheading class="mt-2">Every delivery attempt to your endpoint, newest first.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load webhook deliveries">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <flux:skeleton class="h-9 w-32" />
            <flux:skeleton class="h-9 w-32" />
            <flux:skeleton class="h-9 w-32" />
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Time</flux:table.column>
                <flux:table.column>Event</flux:table.column>
                <flux:table.column class="max-md:hidden">Customer</flux:table.column>
                <flux:table.column class="max-md:hidden">Network</flux:table.column>
                <flux:table.column align="end">Amount</flux:table.column>
                <flux:table.column align="end" class="max-lg:hidden">USD</flux:table.column>
                <flux:table.column class="max-md:hidden">Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        @foreach (range(1, 8) as $c)
                            <flux:table.cell @class(['max-md:hidden' => in_array($c, [3, 4, 7]), 'max-lg:hidden' => $c === 6])><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @elseif ($this->endpoint === null)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="No webhook endpoint configured">
            <flux:callout.text>Save an endpoint URL to start receiving deposit webhooks.</flux:callout.text>
            <x-slot name="actions">
                <flux:button href="{{ route('webhook-settings') }}" wire:navigate variant="ghost">Set up endpoint</flux:button>
            </x-slot>
        </flux:callout>
    @else
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="Search reference or tx hash..." size="sm" class="w-full sm:max-w-xs" clearable />
            <flux:select size="sm" wire:model.live="eventFilter" class="w-auto">
                <flux:select.option value="all">All events</flux:select.option>
                @foreach (\App\Livewire\Dashboard\Webhooks::EVENT_OPTIONS as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select size="sm" wire:model.live="statusFilter" class="w-auto">
                <flux:select.option value="all">All statuses</flux:select.option>
                @foreach (\App\Livewire\Dashboard\Webhooks::STATUS_OPTIONS as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:date-picker mode="range" size="sm" wire:model.live="range" clearable />
            @if ($this->hasFilters)
                <flux:button variant="ghost" size="sm" wire:click="clearFilters" icon="x-mark">Clear</flux:button>
            @endif
        </div>

        @if ($this->deliveries->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="bolt" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">
                    @if ($this->hasFilters)
                        No deliveries match your filters. Try a different combination.
                    @else
                        No deliveries yet. Attempts will show up here once webhooks are sent.
                    @endif
                </flux:text>
            </div>
        @else
            <flux:table :paginate="$this->deliveries" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column>Time</flux:table.column>
                    <flux:table.column>Event</flux:table.column>
                    <flux:table.column class="max-md:hidden">Customer</flux:table.column>
                    <flux:table.column class="max-md:hidden">Network</flux:table.column>
                    <flux:table.column align="end">Amount</flux:table.column>
                    <flux:table.column align="end" class="max-lg:hidden">USD</flux:table.column>
                    <flux:table.column class="max-md:hidden">Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->deliveries as $delivery)
                        @php
                            $data = $delivery->payload['data'] ?? [];
                            $customerRef = $data['customer_reference'] ?? null;
                            $customer = $customerRef !== null ? $this->customers->get($customerRef) : null;
                            $network = isset($data['network']) && \App\Support\Network::exists($data['network'])
                                ? \App\Support\Network::present($data['network'])
                                : null;
                        @endphp
                        <flux:table.row wire:key="delivery-{{ $delivery->id }}">
                            <flux:table.cell class="whitespace-nowrap">
                                <x-date.human :at="$delivery->created_at" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc" class="font-ledger">{{ $delivery->event }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                @if ($customer)
                                    <flux:link href="{{ route('customers.show', $customer) }}" wire:navigate>{{ $customerRef }}</flux:link>
                                @else
                                    {{ $customerRef ?? '—' }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                @if ($network)
                                    <span class="flex items-center gap-1.5">
                                        <x-crypto-icon :icon="$network['icon']" :badge="$network['badge']" class="size-4" />
                                        {{ $network['label'] }}
                                    </span>
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="font-ledger">
                                @if (isset($data['gross_amount']))
                                    {{ $data['gross_amount'] }} {{ $network['symbol'] ?? '' }}
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end" class="max-lg:hidden font-ledger whitespace-nowrap">
                                @if (isset($data['gross_amount_usd']))
                                    ${{ number_format((float) $data['gross_amount_usd'], 2) }}
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden py-0">
                                <span class="inline-flex items-center gap-1.5">
                                    <flux:badge size="sm" color="{{ $delivery->status === 'delivered' ? 'green' : 'red' }}">{{ ucfirst($delivery->status) }}</flux:badge>
                                    <flux:text size="sm" variant="subtle" class="font-ledger">{{ $delivery->response_code ?? '—' }}</flux:text>
                                </span>
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                <span class="inline-flex items-center gap-1">
                                    <flux:tooltip content="View payload">
                                        <flux:button variant="ghost" size="sm" square icon="code-bracket" wire:click="showPayload({{ $delivery->id }})" />
                                    </flux:tooltip>
                                    @if ($delivery->status === 'failed')
                                        <flux:tooltip content="Retry delivery">
                                            <flux:button variant="ghost" size="sm" square icon="arrow-path" wire:click="redeliver({{ $delivery->id }})" />
                                        </flux:tooltip>
                                    @endif
                                </span>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif

    <flux:modal wire:model.self="payloadModal" class="min-w-[22rem] max-w-2xl">
        @if ($this->payloadDelivery)
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Payload</flux:heading>
                    <flux:text size="sm" variant="subtle" class="mt-1 font-ledger">{{ $this->payloadDelivery->event }} · <x-date.human :at="$this->payloadDelivery->created_at" /></flux:text>
                </div>
                <pre class="font-ledger text-xs whitespace-pre-wrap break-all rounded-lg bg-zinc-100 dark:bg-zinc-950 text-zinc-800 dark:text-zinc-300 p-3">{{ json_encode($this->payloadDelivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Close</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
