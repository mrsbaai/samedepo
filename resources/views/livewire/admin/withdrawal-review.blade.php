<div class="py-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load withdrawal">
            <flux:callout.text>Couldn't load withdrawal details. Please try again.</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'not-found')
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Withdrawal not found">
            <flux:callout.text>This withdrawal request doesn't exist or has already been reviewed.</flux:callout.text>
            <x-slot name="actions">
                <flux:button variant="ghost" href="{{ route('admin.withdrawals') }}" wire:navigate>Back to Queue</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="max-w-md mx-auto">
            <flux:skeleton class="h-8 w-48 mb-6" />
            <div class="space-y-3">
                @foreach (range(1, 6) as $i)
                    <flux:skeleton class="h-5 w-full" />
                @endforeach
            </div>
        </div>
    @elseif ($this->withdrawalRecord)
        <div class="max-w-md mx-auto space-y-6">
            <div class="flex items-center justify-between">
                <flux:heading size="xl">Withdrawal Review</flux:heading>
                <flux:badge size="sm" color="{{ $this->withdrawalRecord->status === 'pending' ? 'amber' : 'zinc' }}">
                    {{ ucfirst($this->withdrawalRecord->status) }}
                </flux:badge>
            </div>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" />
            @endif

            <flux:card class="space-y-3">
                <div class="flex items-center justify-between text-sm">
                    <flux:text variant="subtle">Owner</flux:text>
                    <flux:link href="{{ route('admin.owners.show', $this->withdrawalRecord->user) }}" wire:navigate>
                        {{ $this->withdrawalRecord->user->email }}
                    </flux:link>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <flux:text variant="subtle">Network</flux:text>
                    <span class="flex items-center gap-1.5">
                        <img src="{{ asset('crypto/'.$this->networkMeta['slug'].'.svg') }}" alt="" class="size-4" />
                        {{ $this->networkMeta['label'] }}
                    </span>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex items-center justify-between text-sm">
                    <flux:text variant="subtle">Reserved amount</flux:text>
                    <span class="font-ledger">
                        {{ $this->formattedAmount((float) $this->withdrawalRecord->gross_amount) }} {{ $this->networkMeta['symbol'] }}
                        <flux:text size="sm" variant="subtle" class="inline">(~${{ $this->usdValue((float) $this->withdrawalRecord->gross_amount) }})</flux:text>
                    </span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <flux:text variant="subtle">Estimated network fee</flux:text>
                    <span class="font-ledger">
                        {{ $this->formattedAmount($this->estimatedFee()) }} {{ $this->networkMeta['symbol'] }}
                        <flux:text size="sm" variant="subtle" class="inline">(~${{ $this->usdValue($this->estimatedFee()) }})</flux:text>
                    </span>
                </div>
                <div class="flex items-center justify-between text-sm font-medium">
                    <flux:text variant="subtle">Estimated amount to send</flux:text>
                    <span class="font-ledger">
                        {{ $this->formattedAmount($this->estimatedReceive()) }} {{ $this->networkMeta['symbol'] }}
                        <flux:text size="sm" variant="subtle" class="inline">(~${{ $this->usdValue($this->estimatedReceive()) }})</flux:text>
                    </span>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex items-center justify-between text-sm">
                    <flux:text variant="subtle">Destination</flux:text>
                    <code class="text-xs font-mono truncate max-w-[200px] text-zinc-600 dark:text-zinc-400">
                        {{ $this->withdrawalRecord->destination_address }}
                    </code>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <flux:text variant="subtle">Requested</flux:text>
                    <flux:tooltip content="{{ $this->withdrawalRecord->created_at->format('M j, Y H:i') }} UTC">
                        <span>{{ $this->withdrawalRecord->created_at->diffForHumans() }}</span>
                    </flux:tooltip>
                </div>
            </flux:card>

            @if ($this->withdrawalRecord->status === 'pending')
                <div class="flex items-center gap-2">
                    <flux:button variant="primary" wire:click="confirmApprove">Approve Withdrawal</flux:button>
                    <flux:button variant="ghost" wire:click="confirmDeny">Deny Withdrawal</flux:button>
                    <flux:spacer />
                    <flux:button variant="ghost" size="sm" icon="banknotes" href="{{ url('/admin/treasury') }}" wire:navigate>Check Treasury</flux:button>
                </div>
            @endif
        </div>
    @endif

    {{-- Approve modal --}}
    <flux:modal wire:model.self="showApproveModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Approve withdrawal?</flux:heading>
                <flux:text class="mt-2">
                    Approving this withdrawal sends {{ $this->withdrawalRecord ? $this->formattedAmount($this->estimatedReceive()) : '' }} {{ $this->withdrawalRecord ? $this->networkMeta['symbol'] : '' }} on {{ $this->withdrawalRecord ? $this->networkMeta['label'] : '' }} to {{ $this->withdrawalRecord ? $this->withdrawalRecord->user->email : '' }}.
                    This action cannot be reversed.
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="approve">Approve</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Deny modal --}}
    <flux:modal wire:model.self="showDenyModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deny withdrawal?</flux:heading>
                <flux:text class="mt-2">
                    Denying this withdrawal returns {{ $this->withdrawalRecord ? $this->formattedAmount((float) $this->withdrawalRecord->gross_amount) : '' }} {{ $this->withdrawalRecord ? $this->networkMeta['symbol'] : '' }} on {{ $this->withdrawalRecord ? $this->networkMeta['label'] : '' }} to {{ $this->withdrawalRecord ? $this->withdrawalRecord->user->email : '' }}'s available balance.
                    This action cannot be reversed.
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deny">Deny</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
