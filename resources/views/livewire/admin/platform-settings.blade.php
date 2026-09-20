<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load platform settings">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-8 w-48 mb-8" />
        <div class="space-y-8">
            @foreach (range(1, 3) as $i)
                <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
                    <flux:skeleton class="h-12 w-64" />
                    <flux:skeleton class="h-10 flex-1 max-w-sm" />
                </div>
            @endforeach
        </div>
    @else
        <div class="max-w-5xl mx-auto">
            <flux:heading size="xl" class="mb-2">Platform Settings</flux:heading>
            <flux:subheading class="mb-8">Global configuration affecting all website owners.</flux:subheading>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" class="mb-8" />
            @endif

            {{-- Networks --}}
            <flux:card>
                <div class="mb-4">
                    <flux:heading>Networks</flux:heading>
                    <flux:subheading class="mt-1">Deposit and withdrawal limits per network. Disabled networks are hidden from website owners, the API, and the public pages.</flux:subheading>
                </div>

                <flux:table container:class="overflow-x-auto">
                    <flux:table.columns>
                        <flux:table.column>Network</flux:table.column>
                        <flux:table.column>Enabled</flux:table.column>
                        <flux:table.column>Min deposit</flux:table.column>
                        <flux:table.column>Min withdrawal (USD)</flux:table.column>
                        <flux:table.column>Min sweep (USD)</flux:table.column>
                        <flux:table.column>Profit address</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->networks as $key => $meta)
                            <flux:table.row :key="$key">
                                <flux:table.cell>
                                    <div class="flex items-center gap-2 whitespace-nowrap">
                                        <img src="{{ asset($meta['icon']) }}" alt="" class="size-5 shrink-0" />
                                        <div class="leading-tight">
                                            <div class="font-medium">{{ $meta['label'] }}</div>
                                            <div class="text-xs text-zinc-500">{{ $meta['symbol'] }} · {{ \App\Support\Network::chainLabel($key) }}</div>
                                        </div>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:switch wire:click="requestToggle('{{ $key }}')" :checked="$enabledState[$key]" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="w-32">
                                        <flux:input type="number" wire:model="rows.{{ $key }}.min_deposit" step="0.00000001" min="0" size="sm" />
                                        <flux:error name="rows.{{ $key }}.min_deposit" />
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="w-28">
                                        <flux:input type="number" wire:model="rows.{{ $key }}.withdrawal_min_usd" step="0.01" min="0" size="sm" />
                                        <flux:error name="rows.{{ $key }}.withdrawal_min_usd" />
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="w-28">
                                        <flux:input type="number" wire:model="rows.{{ $key }}.sweep_min_usd" step="0.01" min="0" size="sm" />
                                        <flux:error name="rows.{{ $key }}.sweep_min_usd" />
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="min-w-64">
                                        <flux:input wire:model="rows.{{ $key }}.profit_address" size="sm" class="font-mono" />
                                        <flux:error name="rows.{{ $key }}.profit_address" />
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="text-right">
                                    <flux:button variant="ghost" size="sm" wire:click="saveNetworkRow('{{ $key }}')">Save</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:separator variant="subtle" class="my-6" />

            <div class="max-w-3xl">
            {{-- Deposit Fee --}}
            <flux:card>
                <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
                    <div class="lg:w-72 shrink-0">
                        <flux:heading>Deposit Fee</flux:heading>
                        <flux:subheading class="mt-1">Deducted from every incoming deposit before crediting.</flux:subheading>
                        <flux:text size="sm" class="mt-2 text-zinc-500">samedepo deducts a {{ $depositFee }}% fee before crediting confirmed deposits.</flux:text>
                    </div>
                    <div class="flex-1 max-w-sm">
                        <div class="flex items-center gap-2">
                            <flux:input type="number" wire:model="depositFee" step="0.1" min="0" max="100" size="sm" class="w-24" />
                            <flux:text class="text-sm">%</flux:text>
                            <flux:spacer />
                            <flux:button variant="primary" size="sm" wire:click="confirmSaveFee">Save</flux:button>
                        </div>
                        <flux:error name="depositFee" />
                    </div>
                </div>
            </flux:card>

            <flux:separator variant="subtle" class="my-6" />

            {{-- Withdrawal Mode --}}
            <flux:card>
                <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
                    <div class="lg:w-72 shrink-0">
                        <flux:heading>Default Withdrawal Mode</flux:heading>
                        <flux:subheading class="mt-1">Applied to new website owner accounts.</flux:subheading>
                    </div>
                    <div class="flex-1 max-w-sm space-y-3">
                        <flux:radio.group wire:model="defaultWithdrawalMode">
                            <flux:radio value="instant" label="Instant" description="Withdrawals send immediately." />
                            <flux:radio value="approval" label="Administrator Approval" description="Withdrawals require manual review." />
                        </flux:radio.group>
                        <div class="flex justify-end">
                            <flux:button variant="primary" size="sm" wire:click="confirmSaveMode">Save</flux:button>
                        </div>
                    </div>
                </div>
            </flux:card>

            <flux:separator variant="subtle" class="my-6" />

            {{-- API Request Limit --}}
            <flux:card>
                <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
                    <div class="lg:w-72 shrink-0">
                        <flux:heading>API Request Limit</flux:heading>
                        <flux:subheading class="mt-1">Per-minute cap for each API key.</flux:subheading>
                        <flux:text size="sm" class="mt-2 text-zinc-500">This limit applies to each API key independently. Lowering it may cause integrations to receive 429 responses.</flux:text>
                    </div>
                    <div class="flex-1 max-w-sm">
                        <div class="flex items-center gap-2">
                            <flux:input type="number" wire:model="apiRequestsPerMinute" min="1" step="1" size="sm" class="w-24" />
                            <flux:text class="text-sm">requests / minute</flux:text>
                            <flux:spacer />
                            <flux:button variant="primary" size="sm" wire:click="confirmSaveApiRequests">Save</flux:button>
                        </div>
                        <flux:error name="apiRequestsPerMinute" />
                    </div>
                </div>
            </flux:card>

            <flux:separator variant="subtle" class="my-6" />

            {{-- Profit payout thresholds --}}
            <flux:card>
                <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
                    <div class="lg:w-72 shrink-0">
                        <flux:heading>Profit payouts</flux:heading>
                        <flux:subheading class="mt-1">When a payout is too expensive to be worth it. Payout addresses are set per network above.</flux:subheading>
                    </div>
                    <div class="flex-1 max-w-sm space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <flux:field>
                                <flux:label>Warn when fee is at least (%)</flux:label>
                                <flux:input type="number" wire:model="profitWarnFeePercent" step="0.1" min="0.1" max="100" size="sm" class="w-24" />
                                <flux:error name="profitWarnFeePercent" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Block when fee is at least (%)</flux:label>
                                <flux:input type="number" wire:model="profitBlockFeePercent" step="0.1" min="0.1" max="100" size="sm" class="w-24" />
                                <flux:error name="profitBlockFeePercent" />
                            </flux:field>
                        </div>
                        <flux:text size="sm" class="text-zinc-500">Fees are compared to the payout amount in USD.</flux:text>
                        <div class="flex justify-end">
                            <flux:button variant="primary" size="sm" wire:click="confirmSaveProfit">Save</flux:button>
                        </div>
                    </div>
                </div>
            </flux:card>
            </div>
        </div>
    @endif

    {{-- Enable/disable network modal --}}
    <flux:modal wire:model.self="showToggleModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                @if ($toggleTarget)
                    <flux:heading size="lg">Enable {{ $toggleNetwork ? \App\Support\Network::label($toggleNetwork) : '' }}?</flux:heading>
                    <flux:text class="mt-2">Customers will get {{ $toggleNetwork ? \App\Support\Network::label($toggleNetwork) : '' }} deposit addresses and new deposits will be credited from now on.</flux:text>
                @else
                    <flux:heading size="lg">Disable {{ $toggleNetwork ? \App\Support\Network::label($toggleNetwork) : '' }}?</flux:heading>
                    <flux:text class="mt-2">New deposits won't be detected or credited. Existing balances, deposit addresses, and withdrawals are unaffected.</flux:text>
                @endif
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="{{ $toggleTarget ? 'primary' : 'danger' }}" wire:click="confirmToggle">
                    {{ $toggleTarget ? 'Enable' : 'Disable' }} {{ $toggleNetwork ? \App\Support\Network::label($toggleNetwork) : '' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Fee modal --}}
    <flux:modal wire:model.self="showFeeModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Update deposit fee?</flux:heading>
                <flux:text class="mt-2">All future deposits will have {{ $depositFee }}% deducted.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveFee">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Mode modal --}}
    <flux:modal wire:model.self="showModeModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Change withdrawal mode?</flux:heading>
                <flux:text class="mt-2">New accounts will default to this mode. Existing accounts are unaffected.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveMode">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- API request limit modal --}}
    <flux:modal wire:model.self="showApiRequestsModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Update API request limit?</flux:heading>
                <flux:text class="mt-2">This limit applies to each API key independently. Lowering it may cause integrations to receive 429 responses.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveApiRequests">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Profit payout thresholds modal --}}
    <flux:modal wire:model.self="showProfitModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Save profit payout thresholds?</flux:heading>
                <flux:text class="mt-2">Payouts whose fee is at least {{ $profitWarnFeePercent }}% of the amount will warn, and at {{ $profitBlockFeePercent }}% will be blocked.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveProfit">Save</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
