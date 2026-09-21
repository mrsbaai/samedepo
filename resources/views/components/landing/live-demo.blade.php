@php
    $demoMeta = [
        'bitcoin' => ['balance' => 0.4213, 'min' => 0.0009, 'max' => 0.062],
        'usdt_trc20' => ['balance' => 18420.55, 'min' => 12, 'max' => 2450],
        'usdt_erc20' => ['balance' => 7935.10, 'min' => 12, 'max' => 2450],
        'litecoin' => ['balance' => 41.7, 'min' => 0.4, 'max' => 14],
        'ethereum' => ['balance' => 3.184, 'min' => 0.015, 'max' => 1.2],
        'usdc_erc20' => ['balance' => 6210.00, 'min' => 10, 'max' => 1800],
        'usdt_bep20' => ['balance' => 9310.25, 'min' => 12, 'max' => 2450],
        'usdc_bep20' => ['balance' => 4120.75, 'min' => 10, 'max' => 1800],
        'bnb' => ['balance' => 12.4, 'min' => 0.05, 'max' => 3.5],
    ];
    $fallbackRates = ['BTC' => 63800, 'ETH' => 3150, 'LTC' => 82, 'BNB' => 560];

    $presented = \App\Support\Network::presentAll(enabledOnly: true);
    $rates = \App\Models\UsdValuation::query()->whereIn('network', array_keys($presented))->pluck('conversion_value', 'network');

    $demoNetworks = [];
    foreach ($presented as $key => $network) {
        $demoNetworks[] = $network + ($demoMeta[$key] ?? ['balance' => 100.0, 'min' => 1, 'max' => 500]) + [
            'rate' => isset($rates[$key]) ? (float) $rates[$key] : ($fallbackRates[$network['symbol']] ?? 1.0),
            'icon_url' => \App\Support\CryptoIcon::url($network['icon']),
            'badge_url' => $network['badge'] ? \App\Support\CryptoIcon::url($network['badge']) : null,
        ];
    }
@endphp

<div class="landing-demo" x-data="landingDemo(@js($demoNetworks))">
    <div class="grid grid-cols-1 gap-px overflow-hidden rounded-xl border border-zinc-800 bg-zinc-800/60 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($demoNetworks as $i => $network)
            <div class="bg-zinc-900/80 p-5" x-bind:class="{ 'animate-demo-flash': flashKey === '{{ $network['key'] }}' }">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <x-crypto-icon :icon="$network['icon']" :badge="$network['badge']" class="size-5" />
                        <flux:text size="sm" class="truncate font-medium">{{ $network['label'] }}</flux:text>
                    </div>
                    <flux:button size="xs" variant="ghost" icon:trailing="arrow-up-right" href="{{ route('signup') }}" wire:navigate>Withdraw</flux:button>
                </div>
                <flux:heading size="xl" class="mt-3 font-ledger"
                    x-text="fmtUsd(networks[{{ $i }}].balance * networks[{{ $i }}].rate)"
                >${{ number_format($network['balance'] * $network['rate'], 2) }}</flux:heading>
                <flux:text size="sm" variant="subtle" class="mt-0.5 font-ledger"
                    x-text="fmtCrypto(networks[{{ $i }}].balance, networks[{{ $i }}]) + ' {{ $network['symbol'] }}'"
                >{{ rtrim(rtrim(number_format($network['balance'], min(8, $network['decimals'])), '0'), '.') }} {{ $network['symbol'] }}</flux:text>
            </div>
        @endforeach
    </div>

    <flux:card size="sm" class="mt-4 bg-zinc-900/80!">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Time</flux:table.column>
                <flux:table.column>Customer</flux:table.column>
                <flux:table.column>Network</flux:table.column>
                <flux:table.column align="end">Amount</flux:table.column>
                <flux:table.column align="end">USD</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                <template x-for="row in rows" :key="row.id">
                    <flux:table.row
                        x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="translate-y-1 opacity-0"
                        x-bind:class="{ 'animate-demo-flash': row.id === flashId }"
                    >
                        <flux:table.cell x-text="rel(row.ts)"></flux:table.cell>
                        <flux:table.cell class="font-ledger" x-text="row.customer"></flux:table.cell>
                        <flux:table.cell>
                            <span class="flex items-center gap-2">
                                <span class="relative inline-flex shrink-0 size-4">
                                    <img x-bind:src="row.network.icon_url" alt="" class="block size-full" />
                                    <template x-if="row.network.badge_url">
                                        <img x-bind:src="row.network.badge_url" alt="" class="absolute -bottom-0.5 -right-0.5 size-[55%] rounded-full bg-zinc-900 ring-1 ring-zinc-900" />
                                    </template>
                                </span>
                                <span x-text="row.network.label"></span>
                            </span>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="font-ledger" x-text="fmtCrypto(row.amount, row.network) + ' ' + row.network.symbol"></flux:table.cell>
                        <flux:table.cell align="end" variant="strong" class="font-ledger" x-text="fmtUsd(row.usd)"></flux:table.cell>
                        <flux:table.cell class="py-0">
                            <template x-if="row.status === 'pending'"><flux:badge size="sm" color="amber">Pending</flux:badge></template>
                            <template x-if="row.status === 'credited'"><flux:badge size="sm" color="green">Credited</flux:badge></template>
                        </flux:table.cell>
                    </flux:table.row>
                </template>
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('landingDemo', (networks) => ({
            networks,
            rows: [],
            nextId: 0,
            timer: null,
            now: Date.now(),
            flashId: null,
            flashKey: null,
            onScreen: false,
            reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            usdFmt: new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }),

            init() {
                [8, 21, 37, 58, 84].forEach((ago) => this.rows.push(this.makeRow(Date.now() - ago * 1000, 'credited')));

                setInterval(() => (this.now = Date.now()), 1000);

                new IntersectionObserver((entries) => {
                    this.onScreen = entries[0].isIntersecting;
                    this.onScreen ? this.schedule() : this.pause();
                }, { threshold: 0.1 }).observe(this.$el);

                document.addEventListener('visibilitychange', () => {
                    document.hidden ? this.pause() : this.schedule();
                });
            },

            isStable(network) {
                return ['USDT', 'USDC'].includes(network.symbol);
            },

            pickNetwork() {
                const stables = this.networks.filter((n) => this.isStable(n));
                const btc = this.networks.find((n) => n.key === 'bitcoin');
                const others = this.networks.filter((n) => ! this.isStable(n) && n.key !== 'bitcoin');
                const roll = Math.random();
                if (roll < 0.55 && stables.length) return stables[Math.floor(Math.random() * stables.length)];
                if (roll < 0.8 && btc) return btc;
                const pool = others.length ? others : this.networks;
                return pool[Math.floor(Math.random() * pool.length)];
            },

            randomAmount(network) {
                const value = network.min + Math.random() * (network.max - network.min);
                if (Math.random() < 0.3) {
                    return this.isStable(network)
                        ? Math.max(network.min, Math.round(value / 50) * 50)
                        : +value.toFixed(3);
                }
                return +value.toFixed(this.isStable(network) ? 2 : 4 + Math.floor(Math.random() * 3));
            },

            customerRef() {
                const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                return 'CUST-' + Array.from({ length: 4 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
            },

            makeRow(ts, status = 'pending') {
                const network = this.pickNetwork();
                const amount = this.randomAmount(network);
                return { id: ++this.nextId, ts, customer: this.customerRef(), network, amount, usd: amount * network.rate, status };
            },

            schedule() {
                if (this.timer || document.hidden || ! this.onScreen) return;
                this.timer = setTimeout(() => {
                    this.timer = null;
                    this.tick();
                }, 2000 + Math.random() * 5000);
            },

            pause() {
                clearTimeout(this.timer);
                this.timer = null;
            },

            tick() {
                const row = this.makeRow(Date.now());
                this.rows.unshift(row);
                if (this.rows.length > 5) this.rows.pop();

                setTimeout(() => {
                    const credited = this.rows.find((r) => r.id === row.id);
                    if (! credited) return;
                    credited.status = 'credited';
                    credited.network.balance += credited.amount;

                    if (! this.reduced) {
                        this.flashId = credited.id;
                        this.flashKey = credited.network.key;
                        setTimeout(() => {
                            this.flashId = null;
                            this.flashKey = null;
                        }, 1200);
                    }
                }, 1000);

                this.schedule();
            },

            rel(ts) {
                const s = Math.max(0, Math.round((this.now - ts) / 1000));
                if (s < 10) return 'just now';
                if (s < 60) return s + 's ago';
                if (s < 3600) return Math.floor(s / 60) + 'm ago';
                return Math.floor(s / 3600) + 'h ago';
            },

            fmtUsd(value) {
                return this.usdFmt.format(value);
            },

            fmtCrypto(value, network) {
                const stable = this.isStable(network);
                return new Intl.NumberFormat('en-US', {
                    minimumFractionDigits: stable ? 2 : 4,
                    maximumFractionDigits: stable ? 2 : Math.min(8, network.decimals),
                }).format(value);
            },
        }));
    });
</script>
