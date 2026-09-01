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

            <section>
                <flux:heading size="lg" class="mb-3">Network snapshot</flux:heading>
                <div class="grid gap-4">
                    @foreach ($this->wallets as $wallet)
                        @php($meta = $this->networkMeta($wallet->network))
                        @php($metrics = $this->networkMetrics[$wallet->network])
                        <flux:card class="p-4" :key="$wallet->network">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <img src="{{ asset('crypto/'.$meta['slug'].'.svg') }}" alt="" class="size-5" />
                                        <flux:heading size="sm">{{ $meta['label'] }}</flux:heading>
                                    </div>
                                    <div class="font-mono text-sm break-all">{{ $metrics['address'] }}</div>
                                    <a href="{{ $metrics['explorer_url'] }}" target="_blank" rel="noopener" class="text-sm text-blue-600 hover:underline dark:text-blue-400">View on {{ $meta['label'] }} explorer</a>
                                </div>
                                <flux:button size="sm" wire:click="openPayout('{{ $wallet->network }}')">Send from treasury</flux:button>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mt-4">
                                <div>
                                    <flux:text variant="subtle" size="sm">Available</flux:text>
                                    <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $metrics['available_funds'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                    <div class="text-xs text-zinc-500">${{ $metrics['available_funds_usd'] }} USD</div>
                                </div>
                                <div>
                                    <flux:text variant="subtle" size="sm">Native ({{ $meta['native'] }})</flux:text>
                                    <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $metrics['native_balance'], 8) }} {{ $meta['native'] }}</div>
                                    <div class="text-xs text-zinc-500">${{ $metrics['native_balance_usd'] }} USD</div>
                                </div>
                                <div>
                                    <flux:text variant="subtle" size="sm">Unswept</flux:text>
                                    <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $metrics['unswept_amount'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                    <div class="text-xs text-zinc-500">${{ $metrics['unswept_usd'] }} USD · {{ $metrics['unswept_addresses'] }} address{{ $metrics['unswept_addresses'] === 1 ? '' : 'es' }}</div>
                                </div>
                                <div>
                                    <flux:text variant="subtle" size="sm">Pending withdrawals</flux:text>
                                    <div class="font-mono tabular-nums">{{ $metrics['pending_withdrawals_count'] }} pending</div>
                                    <div class="text-xs text-zinc-500">{{ $this->formattedAmount((float) $metrics['pending_withdrawals_sum'], $meta['decimals']) }} {{ $meta['symbol'] }} · ${{ $metrics['pending_withdrawals_usd'] }} USD</div>
                                </div>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2 mt-4">
                                <div>
                                    <flux:text variant="subtle" size="sm">Revenue (platform fees)</flux:text>
                                    <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $metrics['revenue_fee'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                    <div class="text-xs text-zinc-500">${{ $metrics['revenue_fee_usd'] }} USD</div>
                                </div>
                                <div>
                                    <flux:text variant="subtle" size="sm">Recovered network fees</flux:text>
                                    <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $metrics['revenue_network_fee'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                    <div class="text-xs text-zinc-500">${{ $metrics['revenue_network_fee_usd'] }} USD</div>
                                </div>
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            </section>

            <flux:callout icon="banknotes" color="blue" heading="Grand total" inline>
                <flux:callout.text>Across all networks: <span class="font-mono text-lg font-medium">${{ $this->grandTotalUsd }} USD</span></flux:callout.text>
            </flux:callout>

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
                    <flux:heading size="lg" class="mb-3">Recent sweeps</flux:heading>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Network</flux:table.column>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Recovered</flux:table.column>
                            <flux:table.column>Tx</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->recentSweeps as $sweep)
                                @php($meta = $this->networkMeta($sweep->network))
                                <flux:table.row :key="$sweep->id">
                                    <flux:table.cell>{{ $meta['label'] }}</flux:table.cell>
                                    <flux:table.cell class="font-mono tabular-nums">{{ $this->formattedAmount((float) $sweep->amount, $meta['decimals']) }} {{ $meta['symbol'] }}</flux:table.cell>
                                    <flux:table.cell class="py-0"><flux:badge size="sm" color="{{ $sweep->status === 'confirmed' ? 'green' : ($sweep->status === 'failed' ? 'red' : 'amber') }}">{{ ucfirst($sweep->status) }}</flux:badge></flux:table.cell>
                                    <flux:table.cell class="py-0"><flux:badge size="sm" color="{{ $sweep->fee_recovered_at ? 'green' : 'zinc' }}">{{ $sweep->fee_recovered_at ? 'Recovered' : 'Unbilled' }}</flux:badge></flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs">
                                        @if ($sweep->tx_hash)
                                            <a href="{{ $this->explorerUrl('tx', $sweep->network, $sweep->tx_hash) }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline dark:text-blue-400">{{ Str::limit($sweep->tx_hash, 16) }}</a>
                                        @else
                                            —
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row><flux:table.cell colspan="5">No recent sweeps.</flux:table.cell></flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </section>
                <section>
                    <flux:heading size="lg" class="mb-3">Recent treasury payouts</flux:heading>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Network</flux:table.column>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Tx</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->recentPayouts as $payout)
                                @php($meta = $this->networkMeta($payout->network))
                                <flux:table.row :key="$payout->id">
                                    <flux:table.cell>{{ $meta['label'] }}</flux:table.cell>
                                    <flux:table.cell class="font-mono tabular-nums">{{ $this->formattedAmount((float) $payout->amount, $meta['decimals']) }} {{ $meta['symbol'] }}</flux:table.cell>
                                    <flux:table.cell class="py-0"><flux:badge size="sm" color="{{ $payout->status === 'confirmed' ? 'green' : ($payout->status === 'failed' ? 'red' : ($payout->status === 'sent' ? 'amber' : 'zinc')) }}">{{ ucfirst($payout->status) }}</flux:badge></flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs">
                                        @if ($payout->tx_hash)
                                            <a href="{{ $this->explorerUrl('tx', $payout->network, $payout->tx_hash) }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline dark:text-blue-400">{{ Str::limit($payout->tx_hash, 16) }}</a>
                                        @else
                                            —
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row><flux:table.cell colspan="4">No recent payouts.</flux:table.cell></flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </section>
            </div>

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

    <flux:modal wire:model.self="payoutModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Send from {{ $this->networkMeta($payoutNetwork)['label'] }} treasury</flux:heading>
                @if ($payoutStep === 'form')
                    <flux:text class="mt-2">Enter a destination and amount to preview.</flux:text>
                @elseif ($payoutStep === 'confirm')
                    <flux:text class="mt-2">Review the payout before confirming.</flux:text>
                @endif
            </div>

            @if ($payoutStep === 'form')
                <flux:input wire:model="payoutDestination" label="Destination address" placeholder="{{ $payoutNetwork === 'bitcoin' ? '1A...' : '0x...' }}" />
                @php($payoutMeta = $this->networkMeta($payoutNetwork))
                <flux:input wire:model="payoutAmount" type="number" step="any" label="Amount ({{ $payoutMeta['symbol'] }})" description="Available: {{ $this->formattedAmount((float) ($this->wallets->firstWhere('network', $payoutNetwork)?->available_funds ?? 0), $payoutMeta['decimals']) }} {{ $payoutMeta['symbol'] }}" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button wire:click="resetPayout" variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button wire:click="previewPayout" variant="primary">Preview</flux:button>
                </div>
            @elseif ($payoutStep === 'confirm')
                @php($payoutMeta = $this->networkMeta($payoutNetwork))
                <div class="rounded-md bg-zinc-50 dark:bg-zinc-800 p-3">
                    <div class="font-mono text-lg tabular-nums">{{ $this->formattedAmount((float) $payoutAmount, $payoutMeta['decimals']) }} {{ $payoutMeta['symbol'] }}</div>
                    <div class="text-sm text-zinc-500 break-all">to {{ $payoutDestination }}</div>
                </div>
                <flux:callout variant="warning" icon="exclamation-triangle" heading="This can't be undone" inline>
                    <flux:callout.text>This sends {{ $this->formattedAmount((float) $payoutAmount, $payoutMeta['decimals']) }} {{ $payoutMeta['symbol'] }} from the treasury to the address above. This can't be undone.</flux:callout.text>
                </flux:callout>
                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('payoutStep', 'form')" variant="ghost">Back</flux:button>
                    <flux:button wire:click="confirmPayout" variant="primary">Send now</flux:button>
                </div>
            @elseif ($payoutStep === 'success')
                <flux:callout variant="success" icon="check-circle" heading="Payout sent" inline>
                    <flux:callout.text>Transaction hash: <span class="font-mono">{{ $payoutTxHash }}</span></flux:callout.text>
                </flux:callout>
                <div class="flex justify-end"><flux:modal.close><flux:button wire:click="resetPayout" variant="primary">Done</flux:button></flux:modal.close></div>
            @elseif ($payoutStep === 'error')
                <flux:callout variant="danger" icon="x-circle" heading="Payout failed" inline>
                    <flux:callout.text>{{ $payoutMessage ?? 'The payout could not be sent.' }}</flux:callout.text>
                </flux:callout>
                <div class="flex justify-end"><flux:modal.close><flux:button wire:click="resetPayout" variant="ghost">Close</flux:button></flux:modal.close></div>
            @endif
        </div>
    </flux:modal>
</div>
