<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">Dashboard Home</flux:heading>
        <flux:subheading class="mt-2">Overview of your separate balances, estimated USD values, and recent activity.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load dashboard data">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <div class="flex items-center gap-3 mb-6">
            <flux:skeleton class="h-9 w-32" />
            <flux:separator vertical class="max-lg:hidden my-2" />
            <flux:skeleton class="h-7 w-20" />
            <flux:skeleton class="h-7 w-20" />
            <flux:skeleton class="h-7 w-20" />
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            @foreach (range(1, 4) as $i)
                <flux:card variant="soft" class="py-3 px-4">
                    <flux:skeleton class="h-3 w-1/2 mb-2" />
                    <flux:skeleton class="h-6 w-2/3" />
                </flux:card>
            @endforeach
        </div>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Time</flux:table.column>
                <flux:table.column>Customer</flux:table.column>
                <flux:table.column class="max-md:hidden">Network</flux:table.column>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column class="max-md:hidden">Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-14" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <div class="flex items-center gap-3 mb-6">
            <flux:select size="sm" wire:model.live="period" class="w-auto">
                <flux:select.option value="7">7 days</flux:select.option>
                <flux:select.option value="14">14 days</flux:select.option>
                <flux:select.option value="30">30 days</flux:select.option>
                <flux:select.option value="60">60 days</flux:select.option>
                <flux:select.option value="90">90 days</flux:select.option>
            </flux:select>

            <flux:separator vertical class="max-lg:hidden my-2" />

            <div class="max-lg:hidden flex items-center gap-2">
                <flux:badge as="button" rounded size="sm" color="{{ $this->networkFilter === 'all' ? 'amber' : 'zinc' }}" wire:click="$set('networkFilter', 'all')">All</flux:badge>
                <flux:badge as="button" rounded size="sm" color="{{ $this->networkFilter === 'bitcoin' ? 'amber' : 'zinc' }}" wire:click="$set('networkFilter', 'bitcoin')"><span class="flex items-center gap-1"><img src="{{ asset('crypto/bitcoin.svg') }}" alt="" class="size-3.5" /> BTC</span></flux:badge>
                <flux:badge as="button" rounded size="sm" color="{{ $this->networkFilter === 'usdt-trc20' ? 'amber' : 'zinc' }}" wire:click="$set('networkFilter', 'usdt-trc20')"><span class="flex items-center gap-1"><img src="{{ asset('crypto/usdt-trc20.svg') }}" alt="" class="size-3.5" /> TRC20</span></flux:badge>
                <flux:badge as="button" rounded size="sm" color="{{ $this->networkFilter === 'usdt-erc20' ? 'amber' : 'zinc' }}" wire:click="$set('networkFilter', 'usdt-erc20')"><span class="flex items-center gap-1"><img src="{{ asset('crypto/usdt-erc20.svg') }}" alt="" class="size-3.5" /> ERC20</span></flux:badge>
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            @foreach ($this->stats as $stat)
                <flux:card variant="soft" class="py-3 px-4">
                    <flux:text size="sm">{{ $stat['label'] }}</flux:text>
                    <flux:heading size="lg" class="mt-1 font-ledger">{{ $stat['value'] }}</flux:heading>
                </flux:card>
            @endforeach
        </div>

        @if (empty($this->filteredActivity))
            <div class="py-12 text-center">
                <flux:icon icon="inbox" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">Nothing here yet. Once a customer sends Bitcoin, USDT (TRC20), or USDT (ERC20) to one of their deposit addresses, it shows up here the moment we detect it.</flux:text>
            </div>
        @else
            @php
                $statusColors = [
                    'detected' => 'zinc', 'pending' => 'amber', 'credited' => 'green',
                    'approved' => 'green', 'sent' => 'green', 'denied' => 'zinc', 'failed' => 'red',
                ];
            @endphp
            <flux:table :paginate="$this->paginatedActivity" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column>Time</flux:table.column>
                    <flux:table.column>Customer</flux:table.column>
                    <flux:table.column class="max-md:hidden">Network</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column class="max-md:hidden">Status</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($this->paginatedActivity as $item)
                        <flux:table.row wire:key="activity-{{ $loop->index }}">
                            <flux:table.cell class="whitespace-nowrap">
                                <flux:tooltip content="{{ date('M j, Y H:i', strtotime($item['timestamp'])) }} UTC">
                                    <span>{{ \Carbon\Carbon::parse($item['timestamp'])->diffForHumans() }}</span>
                                </flux:tooltip>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($item['customerRef'])
                                    <flux:link href="{{ url('/customers/' . $item['customerRef']) }}" wire:navigate>{{ $item['customerRef'] }}</flux:link>
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                <span class="flex items-center gap-1.5">
                                    <img src="{{ asset('crypto/' . $item['networkSlug'] . '.svg') }}" alt="" class="size-4" />
                                    {{ $item['networkLabel'] }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell variant="strong" class="font-ledger">{{ $item['amount'] }} {{ $item['networkSlug'] === 'bitcoin' ? 'BTC' : 'USDT' }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden py-0">
                                <flux:badge size="sm" color="{{ $statusColors[$item['status']] ?? 'zinc' }}">{{ ucfirst($item['status']) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                @if ($item['txHash'])
                                    <flux:tooltip content="Copy tx hash">
                                        <flux:button variant="ghost" size="sm" icon="clipboard-document" onclick="navigator.clipboard.writeText('{{ $item['txHash'] }}')" />
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
