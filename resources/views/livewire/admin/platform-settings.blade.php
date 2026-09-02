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
        <div class="max-w-3xl mx-auto">
            <flux:heading size="xl" class="mb-2">Platform Settings</flux:heading>
            <flux:subheading class="mb-8">Global configuration affecting all website owners.</flux:subheading>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" class="mb-8" />
            @endif

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

            {{-- Minimum Deposits --}}
            <flux:card>
                <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
                    <div class="lg:w-72 shrink-0">
                        <flux:heading>Minimum Deposits</flux:heading>
                        <flux:subheading class="mt-1">Deposits below these amounts won't be credited.</flux:subheading>
                    </div>
                    <div class="flex-1 max-w-sm space-y-3">
                        <div class="grid grid-cols-3 gap-3">
                            <flux:field>
                                <flux:label>BTC</flux:label>
                                <flux:input type="number" wire:model="minDepositBitcoin" step="0.00000001" min="0" size="sm" />
                                <flux:error name="minDepositBitcoin" />
                            </flux:field>
                            <flux:field>
                                <flux:label>TRC20</flux:label>
                                <flux:input type="number" wire:model="minDepositTrc20" step="0.01" min="0" size="sm" />
                                <flux:error name="minDepositTrc20" />
                            </flux:field>
                            <flux:field>
                                <flux:label>ERC20</flux:label>
                                <flux:input type="number" wire:model="minDepositErc20" step="0.01" min="0" size="sm" />
                                <flux:error name="minDepositErc20" />
                            </flux:field>
                        </div>
                        <div class="flex justify-end">
                            <flux:button variant="primary" size="sm" wire:click="confirmSaveMinDeposit">Save</flux:button>
                        </div>
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

            {{-- Profit Payouts --}}
            <flux:card>
                <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
                    <div class="lg:w-72 shrink-0">
                        <flux:heading>Profit payouts</flux:heading>
                        <flux:subheading class="mt-1">Where samedepo's profit is sent, and when a payout is too expensive to be worth it.</flux:subheading>
                    </div>
                    <div class="flex-1 max-w-sm space-y-3">
                        <flux:field>
                            <flux:label>Bitcoin profit address</flux:label>
                            <flux:input wire:model="profitAddressBitcoin" placeholder="bc1…" class="font-mono" />
                            <flux:error name="profitAddressBitcoin" />
                        </flux:field>
                        <flux:field>
                            <flux:label>USDT (TRC20) profit address</flux:label>
                            <flux:input wire:model="profitAddressUsdtTrc20" placeholder="T…" class="font-mono" />
                            <flux:error name="profitAddressUsdtTrc20" />
                        </flux:field>
                        <flux:field>
                            <flux:label>USDT (ERC20) profit address</flux:label>
                            <flux:input wire:model="profitAddressUsdtErc20" placeholder="0x…" class="font-mono" />
                            <flux:error name="profitAddressUsdtErc20" />
                        </flux:field>
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
    @endif

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

    {{-- Min deposit modal --}}
    <flux:modal wire:model.self="showMinDepositModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Update minimum deposits?</flux:heading>
                <flux:text class="mt-2">Deposits below these amounts won't be credited.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveMinDeposit">Confirm</flux:button>
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

    {{-- Profit payouts modal --}}
    <flux:modal wire:model.self="showProfitModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Save profit payout settings?</flux:heading>
                <flux:text class="mt-2">Future profit payouts will go to the addresses shown. Double-check them — funds sent to a wrong address cannot be recovered.</flux:text>
                <div class="mt-4 space-y-2 font-mono text-sm">
                    <div>Bitcoin: {{ $profitAddressBitcoin ?: 'Not set' }}</div>
                    <div>USDT (TRC20): {{ $profitAddressUsdtTrc20 ?: 'Not set' }}</div>
                    <div>USDT (ERC20): {{ $profitAddressUsdtErc20 ?: 'Not set' }}</div>
                </div>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveProfit">Save</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
