<x-layouts.public :title="'Deposit addresses that never change'" :description="'samedepo gives every customer of a website owner the same permanent crypto deposit address and automatic top-up tracking.'">

    <x-slot:aboveHeader>
        <div id="hero-background" class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-screen hidden md:block"></div>
    </x-slot:aboveHeader>

    @push('scripts')
        @vite(['resources/js/hero-background.jsx', 'resources/js/cta-laserflow.jsx'])
    @endpush

    {{-- Hero --}}
    <section class="py-12 sm:py-28">
        <div class="grid gap-10 sm:gap-12 lg:grid-cols-2 lg:items-center">
            <div class="text-center lg:text-left">
                <flux:heading size="xl" level="1" class="text-4xl sm:text-5xl font-semibold tracking-tight">
                    Same deposit address.<br />
                    Simpler account top-ups.<br />
                </flux:heading>

                <flux:text size="lg" class="mt-6 text-zinc-400">
                    Give every user a permanent deposit address through one API endpoint. Receive a webhook when
                    their deposit is confirmed, so your app can credit their balance automatically.
                </flux:text>

                <div class="mt-8 flex flex-col items-stretch justify-center gap-3 sm:mt-10 sm:flex-row sm:items-center lg:justify-start">
                    <flux:button href="{{ route('signup') }}" variant="primary" class="justify-center" wire:navigate>Create a free account</flux:button>
                    <flux:button href="{{ route('public.api-docs') }}" variant="ghost" class="justify-center" wire:navigate>Read the API docs</flux:button>
                </div>

            </div>

            <div class="animate-float overflow-hidden rounded-xl border border-zinc-700 bg-zinc-950 shadow-2xl shadow-black/40">
                <div class="flex items-center gap-1.5 border-b border-zinc-800 px-4 py-3">
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                </div>
                <pre class="overflow-hidden whitespace-pre-wrap break-words p-5 font-mono leading-6 text-zinc-300 sm:p-6 text-[clamp(0.65rem,1.6vw,0.8rem)]"><code><span class="text-(--color-accent)">curl</span> {{ url('/api/v1/customers/cus_482') }} \
  -H "Authorization: Bearer sk_live_..." \
  -H "Accept: application/json"

<span class="text-zinc-500"># Response · 201 Created</span>
{
  <span class="text-zinc-300">"status"</span>: "created",
  <span class="text-zinc-300">"data"</span>: {
    <span class="text-zinc-300">"customer_reference"</span>: "cus_482",
    <span class="text-zinc-300">"addresses"</span>: [
      { <span class="text-zinc-300">"network"</span>: "bitcoin", <span class="text-zinc-300">"address"</span>: <span class="text-(--color-accent)">"1A1z...DivfNa"</span> },
      { <span class="text-zinc-300">"network"</span>: "usdt_trc20", <span class="text-zinc-300">"address"</span>: <span class="text-(--color-accent)">"TXn9...v4mQ2"</span> },
      { <span class="text-zinc-300">"network"</span>: "usdt_erc20", <span class="text-zinc-300">"address"</span>: <span class="text-(--color-accent)">"0x4f...B9cE1"</span> }
    ]
  },
  
}
<span class="text-zinc-500"># That's it. Really.</span></code></pre>
            </div>
        </div>
    </section>

    {{-- Supported networks --}}
    <section class="py-12 sm:py-16">
        <div class="flex flex-col gap-8 border-y border-zinc-800 py-8 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-sm">
                <flux:text size="sm" class="font-medium text-(--color-accent)">Three networks. One integration.</flux:text>
                <flux:heading size="lg" level="2" class="mt-2">Give your users permanent deposit addresses.</flux:heading>
            </div>

            <div class="flex flex-wrap gap-x-10 gap-y-4">
                @foreach ([['bitcoin.svg', 'Bitcoin', 'Native SegWit'], ['usdt-trc20.svg', 'USDT', 'TRC20'], ['usdt-erc20.svg', 'USDT', 'ERC20']] as [$icon, $name, $network])
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('crypto/'.$icon) }}" alt="" class="size-8 shrink-0 rounded-full" />
                        <div class="leading-tight">
                            <flux:text class="font-medium text-zinc-100">{{ $name }}</flux:text>
                            <flux:text size="xs" class="font-mono text-zinc-500">{{ $network }}</flux:text>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how-it-works" class="py-20 sm:py-24 scroll-mt-20">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-20">
            <div class="max-w-lg">
                <flux:text size="sm" class="font-medium text-(--color-accent)">How it works</flux:text>
                <flux:heading size="xl" level="2" class="mt-2">One setup. Every deposit after that is automatic.</flux:heading>
                <flux:text size="lg" class="mt-3 text-zinc-400">Your server identifies the customer once. samedepo handles the address and deposit lifecycle from there.</flux:text>
            </div>

            <flux:timeline size="lg" class="[--flux-timeline-item-gap:2rem]">
                @foreach ([
                    ['Send a customer reference', 'Make one GET request with a reference from your system, like cus_482.'],
                    ['Get permanent deposit addresses', 'samedepo returns one permanent address for Bitcoin, USDT (TRC20), and USDT (ERC20).'],
                    ['Receive confirmed deposits', 'We watch for deposits, wait for confirmations, credit your balance, and send your webhook.'],
                ] as [$heading, $copy])
                    <flux:timeline.item>
                        <flux:timeline.indicator>{{ $loop->iteration }}</flux:timeline.indicator>
                        <flux:timeline.content>
                            <flux:heading size="lg">{{ $heading }}</flux:heading>
                            <flux:text class="mt-2 text-zinc-400">{{ $copy }}</flux:text>
                        </flux:timeline.content>
                    </flux:timeline.item>
                @endforeach
            </flux:timeline>
        </div>
    </section>

    <section class="py-20 sm:py-24">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-20">
            <div class="max-w-lg lg:sticky lg:top-24 lg:self-start">
                <flux:text size="sm" class="font-medium text-(--color-accent)">Network infrastructure</flux:text>
                <flux:heading size="xl" level="2" class="mt-2">Lower fees without more work.</flux:heading>
                <flux:text size="lg" class="mt-3 text-zinc-400">Address formats, gas, and key custody are handled for you. Your integration stays the same.</flux:text>
            </div>

            <div class="divide-y divide-zinc-800 border-y border-zinc-800">
                @foreach ([
                    ['bolt', 'SegWit', 'Lower-cost Bitcoin transfers', 'Bitcoin deposits use native SegWit addresses. They reduce transaction size and network fees without changing how customers send Bitcoin.'],
                    ['banknotes', 'Gas', 'Automatic USDT gas handling', 'samedepo handles ETH, TRX, energy, and bandwidth from treasury. Website owners and their customers don\'t need to fund deposit addresses with separate gas balances.'],
                    ['shield-check', 'Custody', 'Isolated transaction signing', 'Private wallet keys never enter the website application. Deposits and withdrawals are signed by an isolated service through authenticated requests.'],
                ] as [$icon, $tag, $heading, $copy])
                    <div class="grid gap-4 py-8 sm:grid-cols-[auto_minmax(0,1fr)] sm:gap-6">
                        <flux:icon :name="$icon" class="size-5 text-(--color-accent)" />
                        <div>
                            <div class="flex items-center gap-3">
                                <flux:heading size="lg" level="3">{{ $heading }}</flux:heading>
                                <flux:text size="xs" class="font-mono uppercase tracking-wider text-zinc-500">{{ $tag }}</flux:text>
                            </div>
                            <flux:text class="mt-2 max-w-xl text-zinc-400">{{ $copy }}</flux:text>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- FAQs --}}
    <section class="py-20 sm:py-24">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-20">
            <div class="max-w-lg lg:sticky lg:top-24 lg:self-start">
                <flux:text size="sm" class="font-medium text-(--color-accent)">FAQs</flux:text>
                <flux:heading size="xl" level="2" class="mt-2">Before you integrate.</flux:heading>
                <flux:text size="lg" class="mt-3 text-zinc-400">Fees, networks, confirmations, and limits, answered in plain terms.</flux:text>
                <flux:button href="{{ route('public.api-docs') }}" variant="ghost" icon:trailing="arrow-right" class="mt-6 -ml-3" wire:navigate>Need the details? Read the API docs</flux:button>
            </div>

            <div>
                @include('livewire.support.partials.faq-accordion')
            </div>
        </div>
    </section>

    {{-- Final CTA --}}
    <section class="py-20 sm:py-28">
        <div
            class="relative h-auto w-full overflow-hidden rounded-2xl"
            x-data="{ x: '50%', y: '50%', on: false }"
            @mousemove="const r = $el.getBoundingClientRect(); x = ($event.clientX - r.left) + 'px'; y = ($event.clientY - r.top) + 'px'; on = true"
            @mouseleave="on = false"
        >
            <div id="cta-laserflow" class="absolute inset-0 z-0"></div>
            <img
                src="{{ asset('images/dashboard.png') }}"
                alt=""
                class="pointer-events-none relative z-10 w-full select-none opacity-0 mix-blend-screen transition-opacity duration-300"
                :class="on && 'opacity-100'"
                :style="`mask-composite: intersect; -webkit-mask-composite: source-in; mask-image: radial-gradient(circle 240px at ${x} ${y}, black 0%, transparent 100%), linear-gradient(to right, transparent 0, black 24px, black calc(100% - 24px), transparent 100%), linear-gradient(to bottom, transparent 0, black 24px, black calc(100% - 24px), transparent 100%); -webkit-mask-image: radial-gradient(circle 240px at ${x} ${y}, black 0%, transparent 100%), linear-gradient(to right, transparent 0, black 24px, black calc(100% - 24px), transparent 100%), linear-gradient(to bottom, transparent 0, black 24px, black calc(100% - 24px), transparent 100%)`"
            />
        </div>
        <div class="rounded-2xl border-2 border-(--color-accent) bg-[#0E0E0E] px-6 py-14 text-center sm:px-14 sm:py-20">
            <div class="mx-auto max-w-2xl">
                <flux:text size="sm" class="font-medium text-(--color-accent)">Get started</flux:text>
                <flux:heading size="xl" level="2" class="mt-3 text-3xl tracking-tight sm:text-5xl">Give every customer an address that stays theirs.</flux:heading>
                <flux:text size="lg" class="mt-5 text-zinc-400">Register them once. We handle deposits, confirmations, credits, and webhooks from there.</flux:text>

                <div class="mt-10 flex flex-col justify-center gap-3 sm:flex-row">
                    <flux:button href="{{ route('signup') }}" variant="primary" icon:trailing="arrow-right" wire:navigate>Create a free account</flux:button>
                    <flux:button href="{{ route('public.api-docs') }}" variant="ghost" icon="code-bracket" wire:navigate>Read the API docs</flux:button>
                </div>

                <div class="mt-12 flex flex-wrap items-center justify-center gap-x-8 gap-y-3">
                    @foreach ([['link', 'Permanent addresses'], ['bolt', 'One API request'], ['bell-alert', 'Webhook on every deposit']] as [$icon, $label])
                        <div class="flex items-center gap-2">
                            <flux:icon :name="$icon" variant="micro" class="text-(--color-accent)" />
                            <flux:text size="sm" class="text-zinc-400">{{ $label }}</flux:text>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

</x-layouts.public>
