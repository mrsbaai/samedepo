<div class="py-8 space-y-8">
    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load treasury data">
            <flux:callout.text>Couldn't load treasury data. Please try again.</flux:callout.text>
            <x-slot name="actions"><flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button></x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-8 w-48" />
        <flux:skeleton class="h-48 w-full" />
    @else
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">Treasury</flux:heading>
                <flux:text variant="subtle">Token reserves, network gas, and automated top-up controls.</flux:text>
            </div>
        </div>

        @if ($message)
            <flux:callout variant="success" icon="check-circle" :heading="$message" />
        @endif

        @foreach ($this->wallets->filter(fn ($wallet) => $this->isLow($wallet)) as $wallet)
            @php($meta = $this->networkMeta($wallet->network))
            <flux:callout variant="warning" icon="exclamation-triangle" heading="Low gas reserve">
                <flux:callout.text>{{ $meta['label'] }} has {{ $wallet->native_balance }} {{ $meta['native'] }}, below the {{ $this->policies[$wallet->network]['reserve_threshold'] }} reserve threshold.</flux:callout.text>
            </flux:callout>
        @endforeach

        @if ($this->wallets->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="wallet" variant="outline" class="mx-auto size-8 text-zinc-400" />
                <flux:text class="mt-3">Treasury wallets have not been provisioned yet.</flux:text>
            </div>
        @else
            <section>
                <flux:heading size="lg" class="mb-3">Wallet balances</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Network</flux:table.column>
                        <flux:table.column>Token balance</flux:table.column>
                        <flux:table.column>Gas balance / resources</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Refreshed</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->wallets as $wallet)
                            @php($meta = $this->networkMeta($wallet->network))
                            <flux:table.row :key="$wallet->id">
                                <flux:table.cell class="flex items-center gap-2">
                                    <img src="{{ asset('crypto/'.$meta['slug'].'.svg') }}" alt="" class="size-5" />
                                    <span class="font-medium">{{ $meta['label'] }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="font-mono tabular-nums">{{ $this->formattedAmount((float) $wallet->available_funds, $meta['decimals']) }} {{ $meta['symbol'] }}</span>
                                    <div class="text-xs text-zinc-500">${{ $this->usdValue((float) $wallet->available_funds, $wallet->network) }} USD</div>
                                </flux:table.cell>
                                <flux:table.cell class="font-mono tabular-nums">
                                    {{ $wallet->native_balance ?? '—' }} {{ $meta['native'] }}
                                    @if ($wallet->network === 'usdt_trc20')
                                        <div class="text-xs text-zinc-500">Energy {{ number_format($wallet->energy ?? 0) }} · Bandwidth {{ number_format($wallet->bandwidth ?? 0) }}</div>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="py-0">
                                    @if (isset($this->policies[$wallet->network]) && $this->policies[$wallet->network]['manual_paused'])
                                        <flux:badge size="sm" color="zinc">Paused</flux:badge>
                                    @elseif ($this->isLow($wallet))
                                        <flux:badge size="sm" color="amber">Low gas</flux:badge>
                                    @elseif ($wallet->native_balance === null)
                                        <flux:badge size="sm" color="zinc">Unknown</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="green">Ready</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-sm">{{ $wallet->refreshed_at?->diffForHumans() ?? 'Never' }}</flux:table.cell>
                                <flux:table.cell class="py-0 text-right"><flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshWallet({{ $wallet->id }})">Refresh</flux:button></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </section>

            @if (count($policies))
                <section>
                    <flux:heading size="lg" class="mb-3">Gas policies</flux:heading>
                    <div class="space-y-4">
                        @foreach ($policies as $network => $policy)
                            @php($meta = $this->networkMeta($network))
                            <flux:card class="p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                                    <div class="flex items-center gap-2">
                                        <flux:heading size="sm">{{ $meta['label'] }}</flux:heading>
                                        <flux:badge size="sm" :color="$policy['manual_paused'] ? 'zinc' : 'green'">{{ $policy['manual_paused'] ? 'Paused' : 'Active' }}</flux:badge>
                                    </div>
                                    <flux:switch :checked="$policy['manual_paused']" wire:click="togglePause('{{ $network }}')" label="Pause automatic gas operations" />
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                    <flux:input size="sm" label="Reserve threshold ({{ $meta['native'] }})" wire:model="policies.{{ $network }}.reserve_threshold" />
                                    <flux:input size="sm" label="Top-up amount" wire:model="policies.{{ $network }}.top_up_amount" />
                                    <flux:input size="sm" label="Maximum top-up" wire:model="policies.{{ $network }}.max_top_up" />
                                    <flux:input size="sm" type="number" label="Alert cooldown (minutes)" wire:model="policies.{{ $network }}.alert_cooldown" />
                                    <div class="flex items-end"><flux:button class="w-full" size="sm" variant="primary" wire:click="savePolicy('{{ $network }}')">Save policy</flux:button></div>
                                </div>
                            </flux:card>
                        @endforeach
                    </div>
                </section>
            @endif

            <div class="grid gap-8 xl:grid-cols-2">
                <section>
                    <flux:heading size="lg" class="mb-3">Open and failed top-ups</flux:heading>
                    <flux:table>
                        <flux:table.columns><flux:table.column>Network</flux:table.column><flux:table.column>Amount</flux:table.column><flux:table.column>Status</flux:table.column><flux:table.column>Detail</flux:table.column></flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->topups as $topup)
                                <flux:table.row :key="$topup->id">
                                    <flux:table.cell>{{ $this->networkMeta($topup->network)['label'] }}</flux:table.cell>
                                    <flux:table.cell class="font-mono tabular-nums">{{ $topup->amount }}</flux:table.cell>
                                    <flux:table.cell class="py-0"><flux:badge size="sm" :color="$topup->status === 'failed' ? 'red' : 'amber'">{{ ucfirst($topup->status) }}</flux:badge></flux:table.cell>
                                    <flux:table.cell class="max-w-48 truncate text-sm">{{ $topup->error_message ?? $topup->tx_hash ?? 'Awaiting broadcast' }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row><flux:table.cell colspan="4">No pending or failed top-ups.</flux:table.cell></flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </section>
                <section>
                    <flux:heading size="lg" class="mb-3">Recent gas expenses</flux:heading>
                    <flux:table>
                        <flux:table.columns><flux:table.column>Network</flux:table.column><flux:table.column>Amount</flux:table.column><flux:table.column>Transaction</flux:table.column><flux:table.column>Recorded</flux:table.column></flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->expenses as $expense)
                                <flux:table.row :key="$expense->id">
                                    <flux:table.cell>{{ $this->networkMeta($expense->network)['label'] }}</flux:table.cell>
                                    <flux:table.cell class="font-mono tabular-nums">{{ $expense->amount ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="max-w-36 truncate font-mono text-xs">{{ $expense->tx_hash ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap text-sm">{{ $expense->created_at->diffForHumans() }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row><flux:table.cell colspan="4">No gas expenses recorded.</flux:table.cell></flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </section>
            </div>
        @endif
    @endif
</div>
