<flux:modal wire:model.self="showCreditModal" class="min-w-[22rem]">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Credit this deposit anyway?</flux:heading>
            <flux:text class="mt-2">
                The owner's balance is credited even though this payment {{ ($this->creditDeposit['status'] ?? null) === 'forfeited' ? 'expired' : 'is below the minimum' }}. The normal deposit fee applies.
            </flux:text>
            @if ($this->creditDeposit)
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-4"><span class="text-zinc-500 dark:text-zinc-400">Owner</span><span class="text-right">{{ $this->creditDeposit['owner'] }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500 dark:text-zinc-400">Customer</span><span class="font-ledger text-right">{{ $this->creditDeposit['customerReference'] ?? '—' }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500 dark:text-zinc-400">Network</span><span>{{ $this->creditDeposit['networkLabel'] }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500 dark:text-zinc-400">Gross</span><span class="font-ledger">{{ $this->creditDeposit['gross'] }} {{ $this->creditDeposit['symbol'] }}</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500 dark:text-zinc-400">Fee</span><span class="font-ledger">{{ $this->creditDeposit['feePercent'] }}%</span></div>
                    <div class="flex justify-between gap-4"><span class="text-zinc-500 dark:text-zinc-400">Net credited</span><span class="font-ledger font-medium">{{ $this->creditDeposit['net'] }} {{ $this->creditDeposit['symbol'] }}</span></div>
                </div>
            @endif
        </div>

        <div class="flex gap-2">
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" wire:click="creditAnyway">Credit anyway</flux:button>
        </div>
    </div>
</flux:modal>
