<section class="py-8 sm:py-12">
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <flux:heading size="2xl" level="1">Withdrawal Fee Calculator</flux:heading>
            <flux:text size="lg" class="mt-2">Estimate the network fee and amount received before you withdraw.</flux:text>
        </div>

        <flux:card class="grid gap-6 lg:grid-cols-[16rem_1fr]">
            <div class="space-y-5">
                <flux:select wire:model.live="network" label="Network">
                    <flux:select.option value="bitcoin">Bitcoin</flux:select.option>
                    <flux:select.option value="usdt_trc20">USDT (TRC20)</flux:select.option>
                    <flux:select.option value="usdt_erc20">USDT (ERC20)</flux:select.option>
                </flux:select>
                <flux:input wire:model.live.debounce.500ms="amount" type="number" min="0" step="any" label="Withdrawal amount" />
            </div>

            <div class="border-t border-zinc-200 pt-6 dark:border-white/10 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                @if ($this->belowWithdrawalMinimum)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.text>{{ $this->minimumMessage }}</flux:callout.text>
                    </flux:callout>
                @elseif ($this->withdrawalEstimate)
                    <dl class="space-y-4">
                        <div class="flex items-center justify-between gap-4">
                            <flux:text variant="subtle">Estimated network fee</flux:text>
                            <div class="text-right">
                                <flux:text class="font-ledger tabular-nums">{{ $this->formattedUsd($this->withdrawalEstimate['network_fee']) }}</flux:text>
                                <flux:text size="sm" variant="subtle" class="font-ledger tabular-nums block">({{ $this->formatted($this->withdrawalEstimate['network_fee']) }} {{ $this->networkMeta['symbol'] }})</flux:text>
                            </div>
                        </div>
                        <div class="flex items-end justify-between gap-4 border-t border-zinc-200 pt-4 dark:border-white/10">
                            <flux:heading>Estimated amount received</flux:heading>
                            <div class="text-right">
                                <flux:heading size="xl" class="font-ledger tabular-nums">{{ $this->formattedUsd($this->withdrawalEstimate['receive']) }}</flux:heading>
                                <flux:text size="sm" variant="subtle" class="font-ledger tabular-nums block">({{ $this->formatted($this->withdrawalEstimate['receive']) }} {{ $this->networkMeta['symbol'] }})</flux:text>
                            </div>
                        </div>
                    </dl>
                @else
                    <flux:callout variant="secondary" icon="information-circle">
                        <flux:callout.text>Fee estimate unavailable. The exact fee is calculated when the withdrawal is sent.</flux:callout.text>
                    </flux:callout>
                @endif
            </div>
        </flux:card>
    </div>
</section>
