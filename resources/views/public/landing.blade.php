<x-layouts.public :title="'Permanent crypto deposit addresses for your customers'" :description="'samedepo gives every customer of a website owner the same permanent crypto deposit address and automatic top-up tracking.'">

    {{-- Hero --}}
    <section class="relative isolate overflow-hidden py-20 sm:py-28">
        <div aria-hidden="true" class="absolute -right-32 top-16 -z-10 size-96 rounded-full bg-amber-400/8 blur-3xl"></div>
        <div aria-hidden="true" class="absolute -left-48 bottom-0 -z-10 size-80 rounded-full bg-amber-400/5 blur-3xl"></div>

        <div class="grid gap-16 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
            <div class="text-center lg:text-left">
                <flux:badge rounded icon="check" color="amber" size="sm" class="mb-7">Bitcoin, USDT (TRC20), and USDT (ERC20)</flux:badge>

                <flux:heading size="xl" level="1" class="text-4xl font-semibold tracking-[-0.04em] sm:text-6xl sm:leading-[1.02]">
                    Same customer,<br />
                    same deposit address.<br />
                </flux:heading>

                <flux:text size="lg" class="mt-6 text-zinc-400">
                    Register a customer once, get back a permanent Bitcoin, USDT (TRC20), and USDT (ERC20) address.
                    We watch the chain, credit confirmed deposits, and ping your webhook — no invoices, no expiring
                    links.
                </flux:text>

                <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row lg:justify-start">
                    <flux:button href="{{ route('signup') }}" variant="primary" wire:navigate>Create a free account</flux:button>
                    <flux:button href="{{ route('public.api-docs') }}" variant="ghost" icon:trailing="arrow-right" wire:navigate>Read the API docs</flux:button>
                </div>

                <div class="mt-8 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-sm text-zinc-500 lg:justify-start">
                    <span class="inline-flex items-center gap-1.5"><flux:icon.check variant="micro" class="text-(--color-accent)" /> No invoices</span>
                    <span class="inline-flex items-center gap-1.5"><flux:icon.check variant="micro" class="text-(--color-accent)" /> No expiring links</span>
                    <span class="inline-flex items-center gap-1.5"><flux:icon.check variant="micro" class="text-(--color-accent)" /> Signed webhooks</span>
                </div>
            </div>

            <div class="relative lg:pl-6">
                <div aria-hidden="true" class="absolute -inset-6 -z-10 rounded-3xl bg-gradient-to-br from-amber-400/10 via-transparent to-transparent blur-2xl"></div>
                <div class="animate-float overflow-hidden rounded-2xl bg-zinc-950 shadow-2xl shadow-black/50 ring-1 ring-white/12">
                    <div class="flex items-center gap-1.5 border-b border-white/8 px-4 py-3">
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                    <flux:text size="sm" class="ml-3 font-mono text-zinc-500">register-customer.sh</flux:text>
                </div>
                    <pre class="overflow-x-auto p-5 font-mono text-sm leading-relaxed sm:p-6"><code><span class="text-zinc-500"># Register once, get three permanent addresses</span>
<span class="text-(--color-accent) font-medium">curl</span> https://api.samedepo.com/v1/customers \
  -H "Authorization: Bearer sk_live_..." \
  -d '{"customer_reference": "cus_482"}'

<span class="text-zinc-500"># Response</span>
{
  <span class="text-zinc-300">"customer_reference"</span>: "cus_482",
  <span class="text-zinc-300">"addresses"</span>: {
    <span class="text-zinc-300">"bitcoin"</span>: <span class="text-(--color-accent)">"bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh"</span>,
    <span class="text-zinc-300">"usdt_trc20"</span>: <span class="text-(--color-accent)">"TXn9YbZ...v4mQ2"</span>,
    <span class="text-zinc-300">"usdt_erc20"</span>: <span class="text-(--color-accent)">"0x4f2A1c...B9cE1"</span>
  }
}
<span class="text-zinc-500"># That's it. Seriously.</span></code></pre>
                </div>
                <div class="absolute -bottom-4 right-5 flex items-center gap-2 rounded-full bg-zinc-900 px-3 py-1.5 text-xs text-zinc-300 shadow-lg ring-1 ring-white/10">
                    <span class="size-1.5 rounded-full bg-emerald-400"></span>
                    Permanent addresses returned
                </div>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how-it-works" class="py-20 sm:py-24 scroll-mt-20">
        <div class="max-w-xl">
            <flux:heading size="xl" level="2">Three steps between you and a credited balance.</flux:heading>
            <flux:text size="lg" class="mt-3 text-zinc-400">Register once. Keep the addresses. Let samedepo watch the chains.</flux:text>
        </div>

        <div class="mt-12 grid gap-8 md:grid-cols-3 md:gap-10">
            @foreach ([
                ['Register a customer', 'Send your customer reference. Repeating it returns the same customer.'],
                ['Get permanent addresses', 'One reusable address for each supported network. Nothing to regenerate.'],
                ['Get credited automatically', 'We confirm the deposit, credit your balance, and send the webhook.'],
            ] as [$heading, $copy])
                <div>
                    <span class="flex size-8 items-center justify-center rounded-full bg-amber-400 font-mono text-sm font-semibold text-zinc-950">{{ $loop->iteration }}</span>
                    <flux:heading size="lg" class="mt-5">{{ $heading }}</flux:heading>
                    <flux:text class="mt-2 max-w-xs text-zinc-400">{{ $copy }}</flux:text>
                </div>
            @endforeach
        </div>

        <div class="mt-12 flex items-center gap-3">
            <img src="{{ asset('crypto/bitcoin.svg') }}" alt="Bitcoin" class="size-7" />
            <img src="{{ asset('crypto/usdt-trc20.svg') }}" alt="USDT (TRC20)" class="size-7" />
            <img src="{{ asset('crypto/usdt-erc20.svg') }}" alt="USDT (ERC20)" class="size-7" />
            <flux:text size="sm" class="text-zinc-500">Bitcoin · USDT (TRC20) · USDT (ERC20)</flux:text>
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

    <section class="grid gap-10 py-20 md:grid-cols-2 md:gap-16">
        <div>
            <flux:heading size="lg" level="2">Free for website owners</flux:heading>
            <flux:text class="mt-3 text-zinc-400">No monthly fee or setup cost. We deduct a flat percentage from each confirmed deposit before crediting your balance. Withdrawal network fees are shown before and after the transaction.</flux:text>
        </div>
        <div>
            <flux:heading size="lg" level="2">Clear limits</flux:heading>
            <flux:text class="mt-3 text-zinc-400">This isn't an invoicing tool or customer account system. It supports Bitcoin, USDT (TRC20), and USDT (ERC20), with real blockchain transactions from the first request.</flux:text>
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
