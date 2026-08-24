<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">Transaction History</flux:heading>
        <flux:subheading class="mt-2">Full ledger of deposits and withdrawals with fees broken out per transaction.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load transactions">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <flux:skeleton class="h-9 w-32" />
            <flux:skeleton class="h-9 w-32" />
            <flux:skeleton class="h-9 w-32" />
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Time</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column class="max-md:hidden">Reference</flux:table.column>
                <flux:table.column class="max-md:hidden">Network</flux:table.column>
                <flux:table.column>Gross</flux:table.column>
                <flux:table.column class="max-md:hidden">Fee</flux:table.column>
                <flux:table.column>Net</flux:table.column>
                <flux:table.column class="max-md:hidden">Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        @foreach (range(1, 8) as $c)
                            <flux:table.cell @class(['max-md:hidden' => in_array($c, [3, 4, 6, 8])])><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <flux:select size="sm" wire:model.live="typeFilter" class="w-auto">
                <flux:select.option value="all">All types</flux:select.option>
                <flux:select.option value="deposit">Deposits</flux:select.option>
                <flux:select.option value="withdrawal">Withdrawals</flux:select.option>
            </flux:select>
            <flux:select size="sm" wire:model.live="networkFilter" class="w-auto">
                <flux:select.option value="all">All networks</flux:select.option>
                <flux:select.option value="bitcoin">Bitcoin</flux:select.option>
                <flux:select.option value="usdt-trc20">USDT (TRC20)</flux:select.option>
                <flux:select.option value="usdt-erc20">USDT (ERC20)</flux:select.option>
            </flux:select>
            <flux:select size="sm" wire:model.live="statusFilter" class="w-auto">
                <flux:select.option value="all">All statuses</flux:select.option>
                @foreach ($this->statusOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->paginatedEntries->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="arrows-right-left" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">
                    @if ($typeFilter !== 'all' || $networkFilter !== 'all' || $statusFilter !== 'all')
                        No transactions match your filters. Try a different combination.
                    @else
                        No transactions yet. Deposits and withdrawals will show up here once they happen.
                    @endif
                </flux:text>
            </div>
        @else
            @php
                $statusColors = [
                    'detected' => 'zinc',
                    'pending' => 'amber',
                    'credited' => 'green',
                    'ignored' => 'zinc',
                    'approved' => 'green',
                    'denied' => 'zinc',
                    'cancelled' => 'zinc',
                    'sent' => 'green',
                ];
            @endphp
            <flux:table :paginate="$this->paginatedEntries" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column>Time</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column class="max-md:hidden">Reference</flux:table.column>
                    <flux:table.column class="max-md:hidden">Network</flux:table.column>
                    <flux:table.column>Gross</flux:table.column>
                    <flux:table.column class="max-md:hidden">Fee</flux:table.column>
                    <flux:table.column>Net</flux:table.column>
                    <flux:table.column class="max-md:hidden">Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->paginatedEntries as $tx)
                        <flux:table.row wire:key="tx-{{ $tx['id'] }}">
                            <flux:table.cell class="whitespace-nowrap">
                                <flux:tooltip content="{{ date('M j, Y H:i', strtotime($tx['timestamp'])) }} UTC">
                                    <span>{{ \Carbon\Carbon::parse($tx['timestamp'])->diffForHumans() }}</span>
                                </flux:tooltip>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="{{ $tx['type'] === 'deposit' ? 'green' : 'amber' }}">{{ ucfirst($tx['type']) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                @if ($tx['customer'])
                                    <flux:link href="{{ route('customers.show', $tx['customer']) }}" wire:navigate>{{ $tx['userRef'] }}</flux:link>
                                @else
                                    {{ $tx['userRef'] ?? '—' }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                <span class="flex items-center gap-1.5">
                                    <img src="{{ asset('crypto/' . $tx['networkSlug'] . '.svg') }}" alt="" class="size-4" />
                                    {{ $tx['networkLabel'] }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell class="font-ledger">
                                {{ $tx['gross'] }} {{ $tx['networkSlug'] === 'bitcoin' ? 'BTC' : 'USDT' }}
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden font-ledger">
                                @if ($tx['fee'] !== null)
                                    <flux:tooltip content="{{ $tx['type'] === 'withdrawal' && $tx['status'] !== 'sent' ? 'Estimated network fee' : 'Fee' }}">
                                        <span>{{ $tx['fee'] }} {{ $tx['networkSlug'] === 'bitcoin' ? 'BTC' : 'USDT' }}</span>
                                    </flux:tooltip>
                                @else
                                    &mdash;
                                @endif
                            </flux:table.cell>
                            <flux:table.cell variant="strong" class="font-ledger">
                                @if ($tx['net'] !== null)
                                    {{ $tx['net'] }} {{ $tx['networkSlug'] === 'bitcoin' ? 'BTC' : 'USDT' }}
                                @else
                                    &mdash;
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden py-0">
                                <flux:badge size="sm" color="{{ $statusColors[$tx['status']] ?? 'zinc' }}">{{ ucfirst($tx['status']) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                @if ($tx['txHash'])
                                    <flux:tooltip content="Copy tx hash">
                                        <flux:button variant="ghost" size="sm" icon="clipboard-document" class="font-ledger" onclick="navigator.clipboard.writeText('{{ $tx['txHash'] }}')" />
                                    </flux:tooltip>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</div>
