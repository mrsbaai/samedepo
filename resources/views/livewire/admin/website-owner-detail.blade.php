<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load website owner">
            <flux:callout.text>Couldn't load website owner details. Please try again.</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'not-found')
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Owner not found">
            <flux:callout.text>This website owner account doesn't exist.</flux:callout.text>
            <x-slot name="actions">
                <flux:button variant="ghost" href="{{ route('admin.owners') }}" wire:navigate>Back to Owners</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="max-w-lg mx-auto">
            <flux:skeleton class="h-8 w-48 mb-1" />
            <flux:skeleton class="h-4 w-32 mb-6" />
            <flux:skeleton class="h-24 w-full mb-4" />
            <flux:skeleton class="h-24 w-full" />
        </div>
    @elseif ($this->ownerRecord)
        <div class="max-w-lg mx-auto space-y-6">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $this->ownerRecord->email }}</flux:heading>
                    <flux:badge color="green" size="sm">Active</flux:badge>
                </div>
                <flux:text variant="subtle" size="sm" class="mt-1">#{{ $this->ownerRecord->id }} — Created {{ $this->ownerRecord->created_at->format('M j, Y') }}</flux:text>
            </div>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" />
            @endif

            {{-- Withdrawal Mode --}}
            <flux:card>
                <flux:heading class="mb-4">Withdrawal Mode</flux:heading>
                <div class="space-y-3">
                    <flux:radio.group wire:model="withdrawalMode">
                        <flux:radio value="instant" label="Instant" description="Withdrawals send immediately." />
                        <flux:radio value="approval" label="Administrator Approval" description="Withdrawals require approval." />
                    </flux:radio.group>
                    <div class="flex justify-end">
                        <flux:button variant="primary" size="sm" wire:click="confirmSaveMode">Save Mode</flux:button>
                    </div>
                </div>
            </flux:card>

            {{-- Fee Override --}}
            <flux:card>
                <flux:heading>Deposit Fee Override</flux:heading>
                <flux:subheading class="mb-4">
                    @if ($this->ownerRecord->deposit_fee_override !== null)
                        Current override: {{ $this->ownerRecord->deposit_fee_override }}%
                    @else
                        Currently using the platform default fee.
                    @endif
                </flux:subheading>
                <div class="space-y-3">
                    <flux:field>
                        <flux:label>Fee override (%)</flux:label>
                        <div class="flex items-center gap-2 max-w-xs">
                            <flux:input type="number" wire:model="feeOverride" step="0.1" min="0" max="100" size="sm" placeholder="Platform default" />
                            <flux:text>%</flux:text>
                        </div>
                        <flux:description>Leave blank to use the platform default.</flux:description>
                    </flux:field>
                    <div class="flex justify-end">
                        <flux:button variant="primary" size="sm" wire:click="confirmSaveFee">Save Fee</flux:button>
                    </div>
                </div>
            </flux:card>

            <flux:button variant="ghost" size="sm" icon="banknotes" href="{{ route('admin.owners') }}" wire:navigate>View Pending Withdrawals</flux:button>
        </div>
    @endif

    {{-- Mode modal --}}
    <flux:modal wire:model.self="showModeModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Change withdrawal mode?</flux:heading>
                <flux:text class="mt-2">Changing a website owner's withdrawal mode affects how their future withdrawals are processed.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveMode">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Fee modal --}}
    <flux:modal wire:model.self="showFeeModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Change fee override?</flux:heading>
                <flux:text class="mt-2">Changing a website owner's deposit fee override affects the net amount credited on their future deposits.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveFee">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
