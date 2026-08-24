<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load withdrawal settings">
            <flux:callout.text>Couldn't load withdrawal minimums. Please try again.</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-8 w-56 mb-8" />
        <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
            <flux:skeleton class="h-12 w-64" />
            <div class="flex-1 max-w-sm space-y-3">
                <flux:skeleton class="h-10 w-full" />
                <flux:skeleton class="h-10 w-full" />
                <flux:skeleton class="h-10 w-full" />
            </div>
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <flux:heading size="xl" class="mb-2">Withdrawal Minimums</flux:heading>
            <flux:subheading class="mb-8">Website owners can't request a withdrawal until their balance exceeds these amounts.</flux:subheading>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" class="mb-8" />
            @endif

            <flux:card>
                <div class="flex flex-col lg:flex-row gap-4 lg:gap-8">
                    <div class="lg:w-72 shrink-0">
                        <flux:heading>Minimum Amount (USD)</flux:heading>
                        <flux:subheading class="mt-1">Per-network threshold for withdrawal eligibility.</flux:subheading>
                    </div>
                    <div class="flex-1 max-w-sm space-y-4">
                        <div class="grid grid-cols-3 gap-3">
                            <flux:field>
                                <flux:label>Bitcoin</flux:label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-500 text-sm">$</span>
                                    <flux:input type="number" wire:model="minBitcoin" step="0.01" min="0.01" size="sm" class="pl-7" />
                                </div>
                                <flux:error name="minBitcoin" />
                            </flux:field>
                            <flux:field>
                                <flux:label>USDT TRC20</flux:label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-500 text-sm">$</span>
                                    <flux:input type="number" wire:model="minTrc20" step="0.01" min="0.01" size="sm" class="pl-7" />
                                </div>
                                <flux:error name="minTrc20" />
                            </flux:field>
                            <flux:field>
                                <flux:label>USDT ERC20</flux:label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-500 text-sm">$</span>
                                    <flux:input type="number" wire:model="minErc20" step="0.01" min="0.01" size="sm" class="pl-7" />
                                </div>
                                <flux:error name="minErc20" />
                            </flux:field>
                        </div>
                        <div class="flex justify-end">
                            <flux:button variant="primary" size="sm" wire:click="confirmSave">Save Minimums</flux:button>
                        </div>
                    </div>
                </div>
            </flux:card>
        </div>
    @endif

    <flux:modal wire:model.self="showConfirmModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Update withdrawal minimums?</flux:heading>
                <flux:text class="mt-2">Changing the USD withdrawal minimum affects whether website owners can request withdrawals for each network.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="save">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
