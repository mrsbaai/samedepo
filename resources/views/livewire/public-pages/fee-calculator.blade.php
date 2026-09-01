<section class="py-8 sm:py-12">
    <div class="mx-auto max-w-4xl space-y-8">
        <div>
            <flux:heading size="2xl" level="1">Fee Calculator</flux:heading>
            <flux:text size="lg" class="mt-2">See what a deposit credits and what a withdrawal sends.</flux:text>
        </div>

        <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
            <flux:card class="space-y-5">
                <flux:select wire:model.live="network" label="Network">
                    <flux:select.option value="bitcoin">Bitcoin</flux:select.option>
                    <flux:select.option value="usdt_trc20">USDT (TRC20)</flux:select.option>
                    <flux:select.option value="usdt_erc20">USDT (ERC20)</flux:select.option>
                </flux:select>
                <flux:input wire:model.live.debounce.500ms="amount" type="number" min="0" step="any" label="Amount" />
            </flux:card>

            <div class="divide-y divide-white/10 border-y border-white/10">
                <section class="py-6">
                    <flux:heading size="lg" level="2">Deposit</flux:heading>
                    <flux:text class="mt-1">samedepo deducts a {{ number_format((float) $this->depositFeePercent, 2) }}% fee before crediting confirmed deposits.</flux:text>

                    @if ($this->belowDepositMinimum)
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                            <flux:callout.text>Below the {{ $this->formatted($this->depositMinimum) }} {{ $this->networkMeta['symbol'] }} minimum — deposits under this amount are not credited.</flux:callout.text>
                        </flux:callout>
                    @else
                        <dl class="mt-5 space-y-3">
                            <div class="flex justify-between gap-4"><flux:text variant="subtle">Platform fee ({{ number_format((float) $this->depositFeePercent, 2) }}%)</flux:text><flux:text class="font-ledger tabular-nums">{{ $this->formatted($this->depositResults['fee']) }} {{ $this->networkMeta['symbol'] }}</flux:text></div>
                            <div class="flex justify-between gap-4"><flux:heading>Credited amount</flux:heading><flux:heading class="font-ledger tabular-nums">{{ $this->formatted($this->depositResults['credited']) }} {{ $this->networkMeta['symbol'] }}</flux:heading></div>
                        </dl>
                    @endif
                </section>

                <section class="py-6">
                    <flux:heading size="lg" level="2">Withdrawal</flux:heading>
                    @if ($this->belowWithdrawalMinimum)
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                            <flux:callout.text>The minimum withdrawal is ${{ number_format((float) $this->withdrawalMinimumUsd, 2) }} USD for {{ $this->networkMeta['label'] }}.</flux:callout.text>
                        </flux:callout>
                    @elseif ($this->withdrawalEstimate)
                        <dl class="mt-5 space-y-3">
                            <div class="flex justify-between gap-4"><flux:text variant="subtle">Estimated network fee</flux:text><flux:text class="font-ledger tabular-nums">{{ $this->formatted($this->withdrawalEstimate['network_fee']) }} {{ $this->networkMeta['symbol'] }}</flux:text></div>
                            <div class="flex justify-between gap-4"><flux:heading>Estimated amount received</flux:heading><flux:heading class="font-ledger tabular-nums">{{ $this->formatted($this->withdrawalEstimate['receive']) }} {{ $this->networkMeta['symbol'] }}</flux:heading></div>
                        </dl>
                    @else
                        <flux:callout variant="secondary" icon="information-circle" class="mt-4"><flux:callout.text>Fee estimate unavailable. The exact fee is calculated when the withdrawal is sent.</flux:callout.text></flux:callout>
                    @endif
                    <flux:text size="sm" variant="subtle" class="mt-4">Accumulated network costs from consolidating your deposits are also deducted at withdrawal.</flux:text>
                </section>
            </div>
        </div>
    </div>
</section>
