<div class="py-8 space-y-8">
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
        <flux:skeleton class="h-8 w-48" />
        <flux:skeleton class="h-48 w-full" />
        <flux:skeleton class="h-48 w-full" />
    @elseif ($this->ownerRecord)
        @php($f = $this->finance)

        {{-- Header --}}
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $this->ownerRecord->email }}</flux:heading>
                    <flux:badge size="sm" :color="$this->ownerRecord->is_active ? 'green' : 'zinc'">
                        {{ $this->ownerRecord->is_active ? 'Active' : 'Inactive' }}
                    </flux:badge>
                </div>
                <flux:text variant="subtle" size="sm" class="mt-1">
                    #{{ $this->ownerRecord->id }}
                    · Owner since {{ $this->ownerRecord->created_at->format('M j, Y') }}
                    · Mode: {{ $this->withdrawalModeLabel }}
                    · Fee: {{ $this->ownerRecord->deposit_fee_override ?? \App\Models\PlatformSettings::instance()->global_deposit_fee_percent }}% {{ $this->ownerRecord->deposit_fee_override !== null ? 'override' : 'default' }}
                </flux:text>
            </div>

            <flux:button variant="ghost" size="sm" icon="arrow-left" href="{{ route('admin.owners') }}" wire:navigate>Owners</flux:button>
        </div>

        {{-- Stats + per-network table --}}
        <flux:card>
            <div class="grid grid-cols-2 gap-6 lg:grid-cols-4">
                <div>
                    <flux:text size="sm" class="text-zinc-500">Customers</flux:text>
                    <flux:heading size="xl" class="mt-1 tabular-nums">{{ number_format($f['customers_total']) }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">+{{ $f['customers_new_30d'] }} in 30 days</flux:text>
                </div>
                <div>
                    <flux:text size="sm" class="text-zinc-500">Deposited</flux:text>
                    <flux:heading size="xl" class="mt-1 tabular-nums">${{ number_format((float) $f['totals']['deposit_volume_usd'], 2) }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">{{ number_format($f['deposits_count']) }} deposits</flux:text>
                </div>
                <div>
                    <flux:text size="sm" class="text-zinc-500">Earned</flux:text>
                    <flux:heading size="xl" class="mt-1 tabular-nums">${{ number_format((float) $f['totals']['revenue_usd'], 2) }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500 tabular-nums">
                        −${{ number_format((float) $f['totals']['sweep_gas_usd'], 2) }} gas · net ${{ number_format((float) $f['totals']['net_usd'], 2) }}
                    </flux:text>
                    @if (bccomp($f['totals']['unrecovered_gas_usd'], '0', 8) > 0)
                        <flux:tooltip content="Sweep gas we paid that is billed on this owner's next withdrawal.">
                            <flux:badge size="sm" color="amber" class="mt-1">
                                ${{ number_format((float) $f['totals']['unrecovered_gas_usd'], 2) }} unrecovered
                            </flux:badge>
                        </flux:tooltip>
                    @endif
                </div>
                <div>
                    <flux:text size="sm" class="text-zinc-500">We owe</flux:text>
                    <flux:heading size="xl" class="mt-1 tabular-nums">${{ number_format((float) $f['totals']['owed_usd'], 2) }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500">balance + queued withdrawals</flux:text>
                </div>
            </div>

            @unless ($f['rates_available'])
                <flux:text size="sm" class="mt-3 text-zinc-500">USD rates unavailable — showing zero for conversions.</flux:text>
            @endunless

            @php($nativeSymbol = ['bitcoin' => 'BTC', 'usdt_trc20' => 'TRX', 'usdt_erc20' => 'ETH'])

            <flux:table bleed container:class="mt-6">
                <flux:table.columns>
                    <flux:table.column>Network</flux:table.column>
                    <flux:table.column align="end">Deposited</flux:table.column>
                    <flux:table.column align="end">Withdrawn</flux:table.column>
                    <flux:table.column align="end">Earned</flux:table.column>
                    <flux:table.column align="end">Gas spent</flux:table.column>
                    <flux:table.column align="end">Unrecovered</flux:table.column>
                    <flux:table.column align="end">Owed</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($f['networks'] as $network => $n)
                        @php($m = $networkMeta[$network])
                        <flux:table.row :key="$network">
                            <flux:table.cell class="flex items-center gap-2">
                                <img src="{{ asset('crypto/'.$m['slug'].'.svg') }}" alt="" class="size-4" />
                                {{ $m['label'] }}
                            </flux:table.cell>
                            <flux:table.cell align="end" class="font-ledger tabular-nums">{{ number_format((float) $n['deposit_volume'], $m['decimals']) }} {{ $m['symbol'] }}</flux:table.cell>
                            <flux:table.cell align="end" class="font-ledger tabular-nums">{{ number_format((float) $n['withdrawn'], $m['decimals']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="font-ledger tabular-nums">{{ number_format((float) bcadd($n['fee_revenue'], $n['withdrawal_fee_revenue'], 8), $m['decimals']) }}</flux:table.cell>
                            <flux:table.cell align="end" class="font-ledger tabular-nums">{{ rtrim(rtrim(number_format((float) $n['sweep_gas_native'], 8), '0'), '.') ?: '0' }} {{ $nativeSymbol[$network] }}</flux:table.cell>
                            <flux:table.cell align="end" class="font-ledger tabular-nums {{ bccomp($n['unrecovered_gas_native'], '0', 8) > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ rtrim(rtrim(number_format((float) $n['unrecovered_gas_native'], 8), '0'), '.') ?: '0' }} {{ $nativeSymbol[$network] }}</flux:table.cell>
                            <flux:table.cell align="end" variant="strong" class="font-ledger tabular-nums">{{ number_format((float) $n['owed'], $m['decimals']) }} {{ $m['symbol'] }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        {{-- Growth --}}
        <flux:card>
            <div class="flex items-baseline justify-between">
                <flux:heading>Growth</flux:heading>
                <flux:text size="sm" class="text-zinc-500">Last 12 months · deposits at today's rates</flux:text>
            </div>

            <flux:chart wire:model="growth" class="mt-4">
                <flux:chart.viewport class="aspect-[3/1] min-h-40">
                    <flux:chart.svg>
                        <flux:chart.bar field="deposits_usd" class="text-blue-500 dark:text-blue-400" width="60%" />
                        <flux:chart.axis axis="x" field="label">
                            <flux:chart.axis.tick />
                            <flux:chart.axis.line />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" tick-prefix="$" :format="['notation' => 'compact', 'maximumFractionDigits' => 1]">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.cursor />
                    </flux:chart.svg>
                </flux:chart.viewport>
                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="label" />
                    <flux:chart.tooltip.value field="deposits_usd" label="Deposited" prefix="$" :format="['minimumFractionDigits' => 2, 'maximumFractionDigits' => 2]" />
                </flux:chart.tooltip>
            </flux:chart>

            <flux:chart wire:model="growth" class="mt-4">
                <flux:chart.viewport class="aspect-[6/1] min-h-20">
                    <flux:chart.svg>
                        <flux:chart.bar field="new_customers" class="text-emerald-500" width="40%" />
                        <flux:chart.axis axis="x" field="label">
                            <flux:chart.axis.tick />
                            <flux:chart.axis.line />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" :format="['maximumFractionDigits' => 0]">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.cursor />
                    </flux:chart.svg>
                </flux:chart.viewport>
                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="label" />
                    <flux:chart.tooltip.value field="new_customers" label="New customers" />
                </flux:chart.tooltip>
            </flux:chart>

            @if (collect($growth)->every(fn ($row) => $row['deposits_usd'] == 0 && $row['new_customers'] == 0))
                <flux:text size="sm" class="mt-2 text-zinc-500">No credited deposits or new customers in the last 12 months.</flux:text>
            @endif

            <div class="flex justify-center gap-4 pt-3">
                <flux:chart.legend label="Deposited (USD)"><flux:chart.legend.indicator class="bg-blue-500" /></flux:chart.legend>
                <flux:chart.legend label="New customers"><flux:chart.legend.indicator class="bg-emerald-500" /></flux:chart.legend>
            </div>
        </flux:card>

        {{-- Tabs --}}
        <div class="mt-8">
            <flux:tab.group>
                <flux:tabs wire:model="tab" scrollable scrollable:fade>
                    <flux:tab name="customers">Customers <flux:badge size="sm" class="ml-1">{{ $f['customers_total'] }}</flux:badge></flux:tab>
                    <flux:tab name="withdrawals">Withdrawals <flux:badge size="sm" class="ml-1">{{ $this->withdrawals->total() }}</flux:badge></flux:tab>
                    <flux:tab name="settings">Settings</flux:tab>
                </flux:tabs>

                <flux:tab.panel name="customers">
                    <flux:table :paginate="$this->customers" pagination:scroll-to>
                        <flux:table.columns>
                            <flux:table.column>Reference</flux:table.column>
                            <flux:table.column class="max-md:hidden">Since</flux:table.column>
                            <flux:table.column align="end">Deposits</flux:table.column>
                            <flux:table.column align="end">Deposited</flux:table.column>
                            <flux:table.column class="max-md:hidden">Last deposit</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->customers as $c)
                                <flux:table.row :key="$c['id']">
                                    <flux:table.cell variant="strong">
                                        <flux:link href="{{ route('admin.owners.customers.show', [$this->ownerRecord, $c['reference']]) }}" wire:navigate>{{ $c['reference'] }}</flux:link>
                                    </flux:table.cell>
                                    <flux:table.cell class="max-md:hidden whitespace-nowrap">{{ $c['since']->format('M j, Y') }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $c['deposits'] }}</flux:table.cell>
                                    <flux:table.cell align="end" class="font-ledger tabular-nums">${{ number_format((float) $c['usd'], 2) }}</flux:table.cell>
                                    <flux:table.cell class="max-md:hidden">{{ $c['last']?->diffForHumans() ?? '—' }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5">
                                        <flux:text size="sm">This owner has no customers yet.</flux:text>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </flux:tab.panel>

                <flux:tab.panel name="withdrawals">
                    <div class="mb-3 flex justify-end">
                        <flux:select wire:model.live="withdrawalStatus" size="sm" class="w-40">
                            <flux:select.option value="all">All statuses</flux:select.option>
                            @foreach (['pending', 'approved', 'sent', 'denied', 'cancelled'] as $status)
                                <flux:select.option value="{{ $status }}">{{ ucfirst($status) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:table :paginate="$this->withdrawals" pagination:scroll-to>
                        <flux:table.columns>
                            <flux:table.column>Date</flux:table.column>
                            <flux:table.column>Network</flux:table.column>
                            <flux:table.column align="end">Amount</flux:table.column>
                            <flux:table.column align="end" class="max-lg:hidden">Fee</flux:table.column>
                            <flux:table.column align="end" class="max-lg:hidden">Sent</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Tx</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->withdrawals as $w)
                                <flux:table.row :key="$w['id']">
                                    <flux:table.cell class="whitespace-nowrap">{{ $w['at']->format('M j, H:i') }}</flux:table.cell>
                                    <flux:table.cell class="flex items-center gap-2">
                                        <img src="{{ asset('crypto/'.$w['network']['slug'].'.svg') }}" alt="" class="size-4" />
                                        <span class="max-md:hidden">{{ $w['network']['label'] }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell align="end" variant="strong" class="font-ledger tabular-nums">{{ number_format((float) $w['gross'], $w['network']['decimals']) }} {{ $w['network']['symbol'] }}</flux:table.cell>
                                    <flux:table.cell align="end" class="font-ledger tabular-nums max-lg:hidden">{{ $w['fee'] !== null ? number_format((float) $w['fee'], $w['network']['decimals']) : '—' }}</flux:table.cell>
                                    <flux:table.cell align="end" class="font-ledger tabular-nums max-lg:hidden">{{ $w['sent'] !== null ? number_format((float) $w['sent'], $w['network']['decimals']) : '—' }}</flux:table.cell>
                                    <flux:table.cell class="py-0">
                                        <flux:badge size="sm" color="{{ $statusColors[$w['status']] ?? 'zinc' }}">{{ ucfirst($w['status']) }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="py-0 font-mono text-xs">
                                        @if ($w['explorerUrl'])
                                            <flux:link href="{{ $w['explorerUrl'] }}" target="_blank" rel="noopener">{{ substr($w['txHash'], 0, 6) }}…{{ substr($w['txHash'], -4) }}</flux:link>
                                        @else
                                            —
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="7">
                                        <flux:text size="sm">No withdrawals yet.</flux:text>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </flux:tab.panel>

                <flux:tab.panel name="settings">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        @if ($successMessage)
                            <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" class="lg:col-span-2" />
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
                    </div>
                </flux:tab.panel>
            </flux:tab.group>
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
