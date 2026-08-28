<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load withdrawal settings">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="max-w-lg mx-auto">
            <flux:skeleton class="h-8 w-48 mb-6" />
            @foreach (range(1, 3) as $i)
                <flux:skeleton class="h-12 w-full mb-3" />
            @endforeach
        </div>
    @else
        <div class="max-w-lg mx-auto space-y-6">
            <div>
                <flux:heading size="xl">Withdrawal Settings</flux:heading>
                <flux:subheading class="mt-2">Set or change your withdrawal address for each supported network.</flux:subheading>
            </div>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" />
            @endif

            <flux:card>
                <div class="space-y-4">
                    @foreach ($this->networks as $key => $net)
                        <div class="flex items-start gap-3">
                            <img src="{{ asset('crypto/' . $net['slug'] . '.svg') }}" alt="" class="size-5 mt-2.5 shrink-0" />
                            <div class="flex-1 min-w-0">
                                <flux:text size="sm" class="font-medium mb-1">{{ $net['network'] }}</flux:text>
                                @if ($editingNetwork === $key)
                                    <div class="flex items-center gap-2">
                                        <flux:input wire:model="editingAddress" size="sm" class="font-mono flex-1" placeholder="Enter address..." />
                                        <flux:button size="sm" variant="primary" wire:click="confirmSave">Save</flux:button>
                                        <flux:button size="sm" variant="ghost" wire:click="cancelEdit">Cancel</flux:button>
                                    </div>
                                    <flux:error name="editingAddress" />
                                @else
                                    <div class="flex items-center gap-2">
                                        <code class="text-xs font-mono font-ledger truncate text-zinc-600 dark:text-zinc-400 flex-1">{{ $net['address'] ?: '—' }}</code>
                                        <flux:button size="sm" variant="ghost" icon="pencil" wire:click="startEdit('{{ $key }}', '{{ $net['address'] }}')">
                                            {{ $net['address'] ? 'Change' : 'Set' }}
                                        </flux:button>
                                    </div>
                                @endif
                            </div>
                        </div>
                        @if (!$loop->last)
                            <flux:separator variant="subtle" />
                        @endif
                    @endforeach
                </div>
            </flux:card>
        </div>
    @endif

    <flux:modal wire:model.self="showConfirmModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Update withdrawal address?</flux:heading>
                <flux:text class="mt-2">
                    Future withdrawals for {{ $this->networks[$editingNetwork]['network'] ?? 'this network' }} will be sent to this address immediately, without approval or a waiting period.
                    @if (($this->networks[$editingNetwork]['address'] ?? '') !== '')
                        The old address will no longer receive withdrawals.
                    @endif
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveAddress">Confirm</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
