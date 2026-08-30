<x-layouts.public :title="'Deposit addresses that never change'" :description="'samedepo gives every customer of a website owner the same permanent crypto deposit address and automatic top-up tracking.'">

    {{-- Hero --}}
    <section class="py-20 sm:py-28">
        <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            <div class="text-center lg:text-left">
                <div class="mb-6 flex flex-wrap items-center justify-center gap-2 lg:justify-start">
                    <flux:badge icon="check" color="amber" size="sm">No invoices, No expiring links.</flux:badge>
                </div>

                <flux:heading size="xl" level="1" class="text-4xl sm:text-5xl font-semibold tracking-tight">
                    Same customer,<br />
                    same deposit address.<br />
                </flux:heading>

                <flux:text size="lg" class="mt-6 text-zinc-400">
                    Send a customer reference and get permanent Bitcoin, USDT (TRC20), and USDT (ERC20) deposit
                    addresses. The same GET request returns the same addresses every time.
                </flux:text>

                <div class="mt-10 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3">
                    <flux:button href="{{ route('signup') }}" variant="primary" wire:navigate>Create a free account</flux:button>
                    <flux:button href="{{ route('public.api-docs') }}" variant="ghost" wire:navigate>Read the API docs</flux:button>
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
                    ['Your server requests the customer', 'You send', 'A GET request with a reference from your own system, such as cus_482.'],
                    ['samedepo returns permanent addresses', 'You receive', 'One Bitcoin, one USDT (TRC20), and one USDT (ERC20) deposit address.'],
                    ['We credit confirmed deposits', 'Automatic', 'We watch the network, wait for confirmations, credit your balance, and send the webhook.'],
                ] as [$heading, $label, $copy])
                    <flux:timeline.item>
                        <flux:timeline.indicator>{{ $loop->iteration }}</flux:timeline.indicator>
                        <flux:timeline.content>
                            <flux:heading size="lg">{{ $heading }}</flux:heading>
                            <div class="mt-2 flex items-start gap-3">
                                <flux:badge size="sm" color="amber" class="shrink-0">{{ $label }}</flux:badge>
                                <flux:text class="text-zinc-400">{{ $copy }}</flux:text>
                            </div>
                        </flux:timeline.content>
                    </flux:timeline.item>
                @endforeach
            </flux:timeline>
        </div>

        <div class="mt-16">
            <flux:heading size="sm" level="3" class="mb-4 text-zinc-400">Supported networks</flux:heading>
            <flux:card variant="soft" class="flex flex-col gap-5 bg-zinc-800 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex -space-x-2">
                        @foreach (['bitcoin.svg', 'usdt-trc20.svg', 'usdt-erc20.svg'] as $icon)
                            <img src="{{ asset('crypto/'.$icon) }}" alt="" class="size-10 rounded-full ring-2 ring-zinc-800" />
                        @endforeach
                    </div>
                    <div>
                        <flux:heading>Three networks. One integration.</flux:heading>
                        <flux:text size="sm" variant="subtle">Permanent deposit addresses across Bitcoin and USDT.</flux:text>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 sm:justify-end">
                    @foreach (['Bitcoin', 'USDT (TRC20)', 'USDT (ERC20)'] as $network)
                        <flux:badge size="sm">{{ $network }}</flux:badge>
                    @endforeach
                </div>
            </flux:card>
        </div>
    </section>

    <section class="py-20 sm:py-24">
        <div class="rounded-2xl bg-zinc-950/70 p-6 ring-1 ring-white/8 sm:p-10">
            <div class="max-w-xl">
                <flux:text size="sm" class="font-medium text-(--color-accent)">Network infrastructure</flux:text>
                <flux:heading size="xl" level="2" class="mt-2">Lower fees without more work.</flux:heading>
            </div>

            <div class="mt-10 grid gap-10 md:grid-cols-3">
                <div>
                    <span class="flex size-10 items-center justify-center rounded-lg bg-amber-400/10 text-(--color-accent)">
                        <flux:icon.bolt class="size-5" />
                    </span>
                    <flux:heading size="lg" level="3" class="mt-5">Lower-cost Bitcoin transfers</flux:heading>
                    <flux:text class="mt-2 text-zinc-400">Bitcoin deposits use native SegWit addresses. They reduce transaction size and network fees without changing how customers send Bitcoin.</flux:text>
                </div>

                <div>
                    <span class="flex size-10 items-center justify-center rounded-lg bg-amber-400/10 text-(--color-accent)">
                        <flux:icon.banknotes class="size-5" />
                    </span>
                    <flux:heading size="lg" level="3" class="mt-5">Automatic USDT gas handling</flux:heading>
                    <flux:text class="mt-2 text-zinc-400">samedepo handles ETH, TRX, energy, and bandwidth from treasury. Website owners and their customers don't need to fund deposit addresses with separate gas balances.</flux:text>
                </div>

                <div>
                    <span class="flex size-10 items-center justify-center rounded-lg bg-amber-400/10 text-(--color-accent)">
                        <flux:icon.shield-check class="size-5" />
                    </span>
                    <flux:heading size="lg" level="3" class="mt-5">Isolated transaction signing</flux:heading>
                    <flux:text class="mt-2 text-zinc-400">Private wallet keys never enter the website application. Deposits and withdrawals are signed by an isolated service through authenticated requests.</flux:text>
                </div>
            </div>
        </div>
    </section>

    {{-- FAQs --}}
    <section class="py-20 sm:py-24">
        <div class="mx-auto max-w-3xl">
            <flux:heading size="xl" level="2" class="text-center">FAQs</flux:heading>
            <flux:text class="mt-3 text-center text-zinc-400">Answers to common questions about fees, networks, and limits.</flux:text>

            <div class="mt-8">
                @include('livewire.support.partials.faq-accordion')
            </div>
        </div>
    </section>

    {{-- Final CTA --}}
    <section class="py-20 text-center sm:py-28">
        <div class="mx-auto max-w-2xl">
            <flux:heading size="xl" level="2">Give every customer an address that stays theirs.</flux:heading>
            <flux:text size="lg" class="mx-auto mt-4 max-w-xl text-zinc-400">Register them once. We handle deposits, confirmations, credits, and webhooks from there.</flux:text>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <flux:button href="{{ route('signup') }}" variant="primary" wire:navigate>Create a free account</flux:button>
                <flux:button href="{{ route('public.api-docs') }}" variant="ghost" wire:navigate>Read the API docs</flux:button>
            </div>
        </div>
    </section>

</x-layouts.public>
