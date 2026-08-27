<x-layouts.public :title="'Permanent crypto deposit addresses for your customers'" :description="'samedepo gives every customer of a website owner the same permanent crypto deposit address and automatic top-up tracking.'">

    {{-- Hero --}}
    <section class="py-20 sm:py-28">
        <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            <div class="text-center lg:text-left">
                <flux:badge icon="check" color="amber" size="sm" class="mb-6">Bitcoin, USDT (TRC20), and USDT (ERC20)</flux:badge>

                <flux:heading size="xl" level="1" class="text-4xl sm:text-5xl font-semibold tracking-tight">
                    Same customer,<br />
                    same deposit address.<br />
                </flux:heading>

                <flux:text size="lg" class="mt-6 text-zinc-400">
                    Register a customer once, get back a permanent Bitcoin, USDT (TRC20), and USDT (ERC20) address.
                    We watch the chain, credit confirmed deposits, and ping your webhook — no invoices, no expiring
                    links.
                </flux:text>

                <div class="mt-10 flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3">
                    <flux:button href="{{ route('signup') }}" variant="primary" wire:navigate>Create a free account</flux:button>
                    <flux:button href="{{ route('public.api-docs') }}" variant="ghost" wire:navigate>Read the API docs</flux:button>
                </div>
            </div>

            <div class="animate-float rounded-xl border border-zinc-700 bg-zinc-950 shadow-2xl shadow-black/40 overflow-hidden">
                <div class="flex items-center gap-1.5 px-4 py-3 border-b border-zinc-800">
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                    <span class="size-2.5 rounded-full bg-zinc-700"></span>
                    <flux:text size="sm" class="ml-3 font-mono text-zinc-500">register-customer.sh</flux:text>
                </div>
                <pre class="p-5 text-sm font-mono leading-relaxed overflow-x-auto"><code><span class="text-zinc-500"># Register once, get three permanent addresses</span>
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
        </div>
    </section>

    {{-- How it works --}}
    <section class="py-16 border-t border-zinc-800">
        <div class="text-center max-w-2xl mx-auto mb-16">
            <flux:heading size="lg" level="2">How it works</flux:heading>
            <flux:text class="mt-2 text-zinc-400">Three steps. That's the whole integration.</flux:text>
        </div>

        <div class="max-w-xl mx-auto">
            <flux:timeline size="lg">
                <flux:timeline.item status="complete">
                    <flux:timeline.indicator>1</flux:timeline.indicator>
                    <flux:timeline.content>
                        <flux:heading size="lg">Register a customer</flux:heading>
                        <flux:text class="mt-1 text-zinc-400">
                            Hit the API with your own customer reference. Send the same one twice and you get the
                            same customer back — no dupes, no cleanup.
                        </flux:text>
                    </flux:timeline.content>
                </flux:timeline.item>

                <flux:timeline.item status="complete">
                    <flux:timeline.indicator>2</flux:timeline.indicator>
                    <flux:timeline.content>
                        <flux:heading size="lg">Get permanent addresses</flux:heading>
                        <flux:text class="mt-1 text-zinc-400">
                            You get back permanent Bitcoin, USDT (TRC20), and USDT (ERC20) addresses. Reuse them
                            forever — that's the whole point, no regenerating anything.
                        </flux:text>
                        <div class="mt-3 flex items-center gap-2">
                            <img src="{{ asset('crypto/bitcoin.svg') }}" alt="Bitcoin" title="Bitcoin" class="h-8 w-8" />
                            <img src="{{ asset('crypto/usdt-trc20.svg') }}" alt="USDT (TRC20)" title="USDT (TRC20)" class="h-8 w-8" />
                            <img src="{{ asset('crypto/usdt-erc20.svg') }}" alt="USDT (ERC20)" title="USDT (ERC20)" class="h-8 w-8" />
                        </div>
                    </flux:timeline.content>
                </flux:timeline.item>

                <flux:timeline.item status="current">
                    <flux:timeline.indicator>3</flux:timeline.indicator>
                    <flux:timeline.content>
                        <flux:heading size="lg">Get credited automatically</flux:heading>
                        <flux:text class="mt-1 text-zinc-400">
                            We watch the chain, wait for confirmations, take our cut, and credit the rest to your
                            balance. Then we ping your webhook — you don't have to poll anything.
                        </flux:text>
                    </flux:timeline.content>
                </flux:timeline.item>
            </flux:timeline>
        </div>
    </section>

    <section class="py-16 border-t border-zinc-800">
        <flux:heading size="lg" level="2" class="mb-8">Built to keep network costs down</flux:heading>

        <div class="grid gap-8 md:grid-cols-3">
            <div class="border-t border-zinc-700 pt-5">
                <flux:icon.bolt class="size-6 text-(--color-accent)" />
                <flux:heading size="lg" level="3" class="mt-4">Lower-cost Bitcoin transfers</flux:heading>
                <flux:text class="mt-2 text-zinc-400">
                    Bitcoin deposits use native SegWit addresses. They reduce transaction size and network fees
                    without changing how customers send Bitcoin.
                </flux:text>
            </div>

            <div class="border-t border-zinc-700 pt-5">
                <flux:icon.banknotes class="size-6 text-(--color-accent)" />
                <flux:heading size="lg" level="3" class="mt-4">Automatic USDT gas handling</flux:heading>
                <flux:text class="mt-2 text-zinc-400">
                    samedepo handles ETH, TRX, energy, and bandwidth from treasury. Website owners and their
                    customers don't need to fund deposit addresses with separate gas balances.
                </flux:text>
            </div>

            <div class="border-t border-zinc-700 pt-5">
                <flux:icon.shield-check class="size-6 text-(--color-accent)" />
                <flux:heading size="lg" level="3" class="mt-4">Isolated transaction signing</flux:heading>
                <flux:text class="mt-2 text-zinc-400">
                    Private wallet keys never enter the website application. Deposits and withdrawals are signed
                    by an isolated service through authenticated requests.
                </flux:text>
            </div>
        </div>
    </section>

    {{-- Fee disclosure --}}
    <section class="py-16 max-w-3xl mx-auto">
        <flux:callout icon="banknotes" color="amber">
            <flux:callout.heading>Free for website owners</flux:callout.heading>
            <flux:callout.text>
                No monthly fee, no setup cost. We take a flat percentage of each confirmed deposit — shown right
                in your dashboard, no surprises — before crediting your balance. Withdrawal network fees are shown
                before you withdraw and again once we send it.
            </flux:callout.text>
        </flux:callout>
    </section>

    {{-- What samedepo does not do --}}
    <section class="pb-16 max-w-3xl mx-auto">
        <flux:heading size="lg" level="2" class="mb-4">What samedepo doesn't do</flux:heading>
        <flux:text class="text-zinc-400">
            We're not a one-time payment or invoicing tool, and we don't give you a customer-facing top-up page or
            account system — that part's still on you. Three networks for now: Bitcoin, USDT (TRC20), and USDT
            (ERC20). No test mode — you're on real crypto from the first request.
        </flux:text>
    </section>

    {{-- Final CTA --}}
    <section class="py-20 text-center">
        <flux:card class="max-w-xl mx-auto">
            <flux:heading size="lg" level="2">Integrate samedepo into your website</flux:heading>
            <flux:text class="mt-3 text-zinc-400">
                Then hand every user on your site a permanent deposit address to top up their account with.
            </flux:text>
            <div class="mt-6">
                <flux:button href="{{ route('signup') }}" variant="primary" class="w-full" wire:navigate>Create a free account</flux:button>
            </div>
        </flux:card>
    </section>

</x-layouts.public>
