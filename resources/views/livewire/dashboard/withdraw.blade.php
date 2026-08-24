<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load withdrawal data">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="max-w-md mx-auto">
            <flux:skeleton class="h-8 w-40 mb-6" />
            <flux:skeleton class="h-24 w-full mb-4" />
            <flux:skeleton class="h-10 w-full" />
        </div>
    @else
        <div class="max-w-md mx-auto space-y-6">
            <div class="flex items-center justify-between">
                <flux:heading size="xl">Withdraw {{ $this->networkMeta['label'] }}</flux:heading>
                <flux:badge size="sm" color="{{ $this->mode === 'instant' ? 'green' : 'amber' }}">
                    {{ $this->mode === 'instant' ? 'Instant' : 'Requires approval' }}
                </flux:badge>
            </div>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" />
            @endif

            <flux:card variant="soft" class="py-4 px-5">
                <flux:text size="sm">Available balance</flux:text>
                <flux:heading size="xl" class="font-ledger mt-1">
                    {{ $this->formattedAmount((float) $this->balanceModel->amount) }} {{ $this->networkMeta['symbol'] }}
                </flux:heading>
                <flux:text size="sm" variant="subtle">${{ $this->formattedUsd() }} USD</flux:text>
            </flux:card>

            @if ($this->pendingWithdrawal)
                <flux:card class="space-y-3 p-4">
                    <div class="flex items-center justify-between">
                        <flux:text size="sm" class="font-medium">Pending withdrawal</flux:text>
                        <flux:badge size="sm" color="amber">{{ ucfirst($this->pendingWithdrawal->status) }}</flux:badge>
                    </div>
                    <div class="flex items-center justify-between">
                        <flux:text variant="subtle" size="sm">Amount</flux:text>
                        <flux:text class="font-ledger">
                            {{ $this->formattedAmount((float) $this->pendingWithdrawal->gross_amount) }} {{ $this->networkMeta['symbol'] }}
                        </flux:text>
                    </div>
                    <div class="flex items-center justify-between">
                        <flux:text variant="subtle" size="sm">Requested</flux:text>
                        <flux:text size="sm">{{ $this->pendingWithdrawal->created_at->diffForHumans() }}</flux:text>
                    </div>
                    <flux:button variant="ghost" size="sm" wire:click="confirmCancel" class="w-full">Cancel Withdrawal</flux:button>
                </flux:card>
            @elseif (! $this->eligible)
                <flux:callout variant="warning" icon="exclamation-triangle" heading="Below minimum">
                    <flux:callout.text>
                        This balance's estimated USD value is below the ${{ $this->formattedMinimum() }} minimum required to withdraw.
                    </flux:callout.text>
                </flux:callout>
            @else
                <div class="space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <flux:text variant="subtle">Estimated network fee</flux:text>
                        <flux:text class="font-ledger">— <span class="text-zinc-500 text-xs">(unknown until sent)</span></flux:text>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <flux:text variant="subtle">Estimated amount to be received</flux:text>
                        <flux:text class="font-ledger font-medium">
                            {{ $this->formattedAmount((float) $this->balanceModel->amount) }} {{ $this->networkMeta['symbol'] }}
                        </flux:text>
                    </div>
                    <flux:separator variant="subtle" />
                    <div class="flex items-center justify-between text-sm">
                        <flux:text variant="subtle">Destination</flux:text>
                        <code class="text-xs font-mono truncate max-w-[200px] text-zinc-600 dark:text-zinc-400">{{ $this->withdrawalAddress->address ?? '' }}</code>
                    </div>
                </div>
                <flux:button variant="primary" class="w-full" wire:click="confirmRequest">Withdraw Full Balance</flux:button>
            @endif
        </div>
    @endif

    <flux:modal wire:model.self="showRequestModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Confirm withdrawal?</flux:heading>
                <flux:text class="mt-2">
                    This sends your full {{ $this->networkMeta['label'] }} balance —
                    {{ $this->formattedAmount((float) $this->balanceModel->amount) }} {{ $this->networkMeta['symbol'] }},
                    minus the estimated network fee — to the address ending in {{ $this->addressEnding() }}.
                    Mode: {{ $this->mode === 'instant' ? 'Instant' : 'Requires approval' }}.
                    This can't be undone.
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="requestWithdrawal">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showCancelModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Cancel withdrawal?</flux:heading>
                <flux:text class="mt-2">This returns the reserved balance to your available balance.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Keep</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="cancelWithdrawal">Cancel Withdrawal</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
