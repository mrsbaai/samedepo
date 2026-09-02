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
        <div wire:poll.visible.10s="refreshTreasuryData" class="space-y-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <flux:heading size="xl">Treasury</flux:heading>
                    <flux:text variant="subtle">Profit, network reserves, and operational controls.</flux:text>
                </div>
                <div class="flex items-center gap-3">
                    @php($live = $this->wallets->isEmpty() || ! $this->wallets->contains(fn ($wallet) => $wallet->refreshed_at === null || $wallet->refreshed_at <= now()->subMinutes(2)))
                    <flux:badge size="sm" :color="$live ? 'green' : 'amber'">{{ $live ? 'Live' : 'Stale' }}</flux:badge>
                    <flux:link href="{{ route('admin.platform-settings') }}" class="text-sm">Platform settings</flux:link>
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
                    <flux:heading size="lg" class="mb-3">Profit</flux:heading>
                    <flux:text variant="subtle" class="mb-3">What samedepo can take out, after every owner balance and queued withdrawal is covered.</flux:text>

                    <flux:table container:class="overflow-x-auto">
                        <flux:table.columns>
                            <flux:table.column>Network</flux:table.column>
                            <flux:table.column>Withdrawable</flux:table.column>
                            <flux:table.column>Total profit</flux:table.column>
                            <flux:table.column>Owner liabilities</flux:table.column>
                            <flux:table.column>Paid out</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->profit['networks'] as $network => $p)
                                @php($meta = $this->networkMeta($network))
                                <flux:table.row :key="$network">
                                    <flux:table.cell class="flex items-center gap-2">
                                        <img src="{{ asset('crypto/'.$meta['slug'].'.svg') }}" alt="" class="size-5" />
                                        <span class="font-medium">{{ $meta['label'] }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $p['withdrawable'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                        <div class="text-xs text-zinc-500">${{ number_format((float) $p['withdrawable_usd'], 2) }} USD</div>
                                        @if (bccomp($p['equity'], $p['withdrawable'], 8) > 0)
                                            @php($diff = bcsub($p['equity'], $p['withdrawable'], 8))
                                            <div class="text-xs text-zinc-500">{{ rtrim(rtrim($diff, '0'), '.') }} {{ $meta['symbol'] }} is still on deposit addresses — withdrawable after the next sweep.</div>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="{{ bccomp($p['equity'], '0', 8) < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                        <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $p['equity'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                        <div class="text-xs text-zinc-500">${{ number_format((float) $p['equity_usd'], 2) }} USD</div>
                                        @if (bccomp($p['equity'], '0', 8) < 0)
                                            <flux:badge size="sm" color="red" class="mt-1">Deficit</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $p['liabilities'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $p['paid_out'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right py-0">
                                        @if (! $this->profitAddresses[$network])
                                            <flux:link href="{{ route('admin.platform-settings') }}" class="text-sm">Set payout address</flux:link>
                                        @elseif (bccomp($p['withdrawable'], '0', 8) <= 0)
                                            <flux:tooltip content="Nothing to withdraw yet"><div><flux:button size="sm" variant="primary" disabled>Withdraw profit</flux:button></div></flux:tooltip>
                                        @else
                                            <flux:button size="sm" variant="primary" wire:click="openPayout('{{ $network }}')">Withdraw profit</flux:button>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    <flux:text size="sm" class="mt-3">Withdrawable now: <span class="font-mono font-medium">${{ number_format((float) $this->profit['total_withdrawable_usd'], 2) }} USD</span> · Total profit: <span class="font-mono">${{ number_format((float) $this->profit['total_equity_usd'], 2) }} USD</span></flux:text>
                </section>

                <section>
                    <flux:heading size="lg" class="mb-3">Network snapshot</flux:heading>
                    <flux:table container:class="overflow-x-auto">
                        <flux:table.columns>
                            <flux:table.column>Network</flux:table.column>
                            <flux:table.column>Address</flux:table.column>
                            <flux:table.column>Available balance</flux:table.column>
                            <flux:table.column>Gas / resources</flux:table.column>
                            <flux:table.column>Unswept</flux:table.column>
                            <flux:table.column>Pending withdrawals</flux:table.column>
                            <flux:table.column>Refreshed</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->wallets as $wallet)
                                @php($meta = $this->networkMeta($wallet->network))
                                @php($metrics = $this->networkMetrics[$wallet->network])
                                <flux:table.row :key="$wallet->id">
                                    <flux:table.cell class="flex items-center gap-2">
                                        <img src="{{ asset('crypto/'.$meta['slug'].'.svg') }}" alt="" class="size-5" />
                                        <span class="font-medium">{{ $meta['label'] }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="font-mono text-sm break-all">{{ $metrics['address'] }}</div>
                                        <a href="{{ $metrics['explorer_url'] }}" target="_blank" rel="noopener" class="text-sm text-blue-600 hover:underline dark:text-blue-400">View on {{ $meta['label'] }} explorer</a>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $metrics['available_funds'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                        <div class="text-xs text-zinc-500">${{ $metrics['available_funds_usd'] }} USD</div>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($wallet->network === 'bitcoin')
                                            <span class="text-sm text-zinc-500">Not applicable</span>
                                        @else
                                            <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $metrics['native_balance'], 8) }} {{ $meta['native'] }}</div>
                                            <div class="text-xs text-zinc-500">${{ $metrics['native_balance_usd'] }} USD</div>
                                            @if (isset($this->policies[$wallet->network]) && $this->policies[$wallet->network]['manual_paused'])
                                                <flux:badge size="sm" color="zinc">Paused</flux:badge>
                                            @elseif ($this->isLow($wallet))
                                                <flux:badge size="sm" color="amber">Low gas</flux:badge>
                                            @elseif ($wallet->native_balance === null)
                                                <flux:badge size="sm" color="zinc">Unknown</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="green">Ready</flux:badge>
                                            @endif
                                            @if ($wallet->network === 'usdt_trc20')
                                                <div class="text-xs text-zinc-500">Energy {{ number_format($wallet->energy ?? 0) }} · Bandwidth {{ number_format($wallet->bandwidth ?? 0) }}</div>
                                            @endif
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="font-mono tabular-nums">{{ $this->formattedAmount((float) $metrics['unswept_amount'], $meta['decimals']) }} {{ $meta['symbol'] }}</div>
                                        <div class="text-xs text-zinc-500">${{ $metrics['unswept_usd'] }} USD · {{ $metrics['unswept_addresses'] }} address{{ $metrics['unswept_addresses'] === 1 ? '' : 'es' }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="font-mono tabular-nums">{{ $metrics['pending_withdrawals_count'] }} pending</div>
                                        <div class="text-xs text-zinc-500">{{ $this->formattedAmount((float) $metrics['pending_withdrawals_sum'], $meta['decimals']) }} {{ $meta['symbol'] }} · ${{ $metrics['pending_withdrawals_usd'] }} USD</div>
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap text-sm">{{ $wallet->refreshed_at?->diffForHumans() ?? 'Never' }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </section>

                <flux:tab.group>
                    <flux:tabs scrollable scrollable:fade>
                        <flux:tab name="gas_controls">Gas controls</flux:tab>
                        <flux:tab name="sweeps">Sweeps</flux:tab>
                        <flux:tab name="profit_payouts">Profit payouts</flux:tab>
                        <flux:tab name="top_ups">Top-ups</flux:tab>
                        <flux:tab name="gas_expenses">Gas expenses</flux:tab>
                    </flux:tabs>

                    <flux:tab.panel name="gas_controls" class="pt-4">
                        @if (count($policies))
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
                        @endif
                    </flux:tab.panel>

                    <flux:tab.panel name="sweeps" class="pt-4">
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
                    </flux:tab.panel>

                    <flux:tab.panel name="profit_payouts" class="pt-4">
                        <flux:heading size="lg" class="mb-3">Profit payouts</flux:heading>
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
                    </flux:tab.panel>

                    <flux:tab.panel name="top_ups" class="pt-4">
                        <flux:heading size="lg" class="mb-3">Open and failed top-ups</flux:heading>
                        <flux:table>
                            <flux:table.columns><flux:table.column>Network</flux:table.column><flux:table.column>Amount</flux:table.column><flux:table.column>Status</flux:table.column><flux:table.column>Detail</flux:table.column></flux:table.columns>
                            <flux:table.rows>
                                @forelse ($this->topups->where('network', '!=', 'bitcoin') as $topup)
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
                    </flux:tab.panel>

                    <flux:tab.panel name="gas_expenses" class="pt-4">
                        <flux:heading size="lg" class="mb-3">Recent gas expenses</flux:heading>
                        <flux:table>
                            <flux:table.columns><flux:table.column>Network</flux:table.column><flux:table.column>Amount</flux:table.column><flux:table.column>Transaction</flux:table.column><flux:table.column>Recorded</flux:table.column></flux:table.columns>
                            <flux:table.rows>
                                @forelse ($this->expenses->where('network', '!=', 'bitcoin') as $expense)
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
                    </flux:tab.panel>
                </flux:tab.group>
            @endif
        </div>
    @endif

    <flux:modal wire:model.self="payoutModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Withdraw {{ $this->networkMeta($payoutNetwork)['label'] }} profit</flux:heading>
                @if ($payoutStep === 'form')
                    <flux:text class="mt-2">Review the amount before sending it to the saved profit address.</flux:text>
                @elseif ($payoutStep === 'confirm')
                    <flux:text class="mt-2">Review the payout before confirming.</flux:text>
                @endif
            </div>

            @if ($payoutStep === 'form')
                @php($payoutMeta = $this->networkMeta($payoutNetwork))
                @php($withdrawable = $this->profit['networks'][$payoutNetwork]['withdrawable'] ?? '0.00000000')
                <div>
                    <flux:text size="sm" variant="subtle">Sends to</flux:text>
                    <div class="font-mono text-sm break-all">{{ $payoutDestination }}</div>
                    <flux:link href="{{ route('admin.platform-settings') }}" class="text-xs">Change in Platform Settings</flux:link>
                </div>
                <flux:input wire:model="payoutAmount" type="number" step="any" max="{{ $withdrawable }}" label="Amount ({{ $payoutMeta['symbol'] }})" description="Withdrawable profit: {{ $this->formattedAmount((float) $withdrawable, $payoutMeta['decimals']) }} {{ $payoutMeta['symbol'] }}" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button wire:click="resetPayout" variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button wire:click="previewPayout" variant="primary">Review payout</flux:button>
                </div>
            @elseif ($payoutStep === 'confirm')
                @php($payoutMeta = $this->networkMeta($payoutNetwork))
                <div class="rounded-md bg-zinc-50 dark:bg-zinc-800 p-3 space-y-1">
                    <div class="font-mono text-lg tabular-nums">{{ $this->formattedAmount((float) $payoutAmount, $payoutMeta['decimals']) }} {{ $payoutMeta['symbol'] }}</div>
                    <div class="text-sm text-zinc-500">${{ number_format((float) ($payoutPreview['amount_usd'] ?? 0), 2) }} USD</div>
                    <div class="text-sm text-zinc-500 break-all">to {{ $payoutDestination }}</div>
                </div>
                @php($level = $payoutPreview['level'] ?? 'block')
                <div class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-zinc-500">Estimated network fee</span>
                        <span class="font-mono tabular-nums">{{ $payoutPreview['fee_native'] ?? '—' }} {{ $payoutMeta['native'] }} @if(isset($payoutPreview['fee_usd']))<span class="text-zinc-500">(${{ number_format((float) $payoutPreview['fee_usd'], 2) }} · {{ number_format((float) $payoutPreview['fee_percent'], 2) }}%)</span>@endif</span>
                    </div>
                    @if ($level === 'ok')
                        <flux:badge size="sm" color="green">Low fee</flux:badge>
                    @elseif ($level === 'warn')
                        <flux:callout variant="warning" icon="exclamation-triangle" heading="High fee" inline><flux:callout.text>Fee is {{ number_format((float) $payoutPreview['fee_percent'], 2) }}% of this payout. Waiting to batch more profit into one payout costs less.</flux:callout.text></flux:callout>
                    @else
                        <flux:callout variant="danger" icon="x-circle" heading="Blocked" inline><flux:callout.text>{{ $payoutPreview['message'] ?? 'This payout cannot be sent right now.' }}</flux:callout.text></flux:callout>
                    @endif
                    @if ($payoutNetwork === 'usdt_trc20')
                        <flux:text size="sm" variant="subtle">Tip: sending to an address that already holds USDT uses about half the energy.</flux:text>
                    @endif
                </div>
                <flux:callout variant="warning" icon="exclamation-triangle" heading="This can't be undone" inline>
                    <flux:callout.text>This sends {{ $this->formattedAmount((float) $payoutAmount, $payoutMeta['decimals']) }} {{ $payoutMeta['symbol'] }} to the saved profit address. This can't be undone.</flux:callout.text>
                </flux:callout>
                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('payoutStep', 'form')" variant="ghost">Back</flux:button>
                    <flux:button wire:click="confirmPayout" variant="primary" :disabled="$level === 'block'">Send {{ $this->formattedAmount((float) $payoutAmount, $payoutMeta['decimals']) }} {{ $payoutMeta['symbol'] }}</flux:button>
                </div>
            @elseif ($payoutStep === 'success')
                <flux:callout variant="success" icon="check-circle" heading="Payout sent" inline>
                    <flux:callout.text>Transaction hash: <a href="{{ $this->explorerUrl('tx', $payoutNetwork, $payoutTxHash) }}" target="_blank" rel="noopener" class="font-mono text-blue-600 hover:underline dark:text-blue-400">{{ $payoutTxHash }}</a></flux:callout.text>
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
