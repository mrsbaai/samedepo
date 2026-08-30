<x-layouts.public :title="'API Documentation'" :description="'Integrate samedepo with our live, automatically-generated API reference.'">
    @php
        $activeTab = in_array(request('tab'), ['limits', 'endpoints', 'webhooks'], true) ? request('tab') : 'quick-start';
        $tabs = [
            'quick-start' => ['Quick start', 'bolt'],
            'limits' => ['Limits & fees', 'adjustments-horizontal'],
            'endpoints' => ['Endpoints', 'code-bracket'],
            'webhooks' => ['Webhooks', 'signal'],
        ];
    @endphp

    <section class="py-8 sm:py-12">
        <div class="mx-auto max-w-5xl min-w-0">
            <flux:heading size="2xl" level="1" class="mb-2">API Documentation</flux:heading>
            <flux:text size="lg" class="text-zinc-400">
            </flux:text>

            <flux:tab.group class="mt-6 min-w-0 sm:mt-10" findable>
                <flux:dropdown class="sm:hidden">
                    <flux:button class="w-full justify-between" :icon="$tabs[$activeTab][1]" icon:trailing="chevron-down">
                        {{ $tabs[$activeTab][0] }}
                    </flux:button>

                    <flux:menu class="w-full">
                        @foreach ($tabs as $name => [$label, $icon])
                            <flux:menu.item href="{{ route('public.api-docs', ['tab' => $name]) }}" :icon="$icon" wire:navigate>
                                {{ $label }}
                                @if ($activeTab === $name)
                                    <flux:icon.check class="ml-auto size-4" />
                                @endif
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>

                <flux:tabs variant="pills" class="max-sm:hidden">
                    @foreach ($tabs as $name => [$label, $icon])
                        <flux:tab :name="$name" :icon="$icon" :selected="$activeTab === $name">{{ $label }}</flux:tab>
                    @endforeach
                </flux:tabs>

                <flux:tab.panel name="quick-start" :selected="$activeTab === 'quick-start'" class="pt-6 sm:pt-8">
                    <ol class="divide-y divide-white/10 border-y border-white/10">
                        <li class="grid gap-3 py-5 md:grid-cols-[12rem_1fr] md:gap-8">
                            <flux:heading level="2">1. Get an API key</flux:heading>
                            <flux:text>
                                Sign in and create a key from <flux:link href="{{ route('api-keys') }}" wire:navigate>API Keys</flux:link>. Keep it secret.
                            </flux:text>
                        </li>
                        <li class="grid gap-3 py-5 md:grid-cols-[12rem_1fr] md:gap-8">
                            <flux:heading level="2">2. Base URL</flux:heading>
                            <code class="block max-w-full overflow-x-auto whitespace-nowrap rounded-lg bg-zinc-950 px-3 py-3 font-mono text-xs text-zinc-300 sm:px-4 sm:text-sm">{{ $baseUrl }}</code>
                        </li>
                        <li class="grid gap-3 py-5 md:grid-cols-[12rem_1fr] md:gap-8">
                            <flux:heading level="2">3. Authenticate</flux:heading>
                            <div>
                                <flux:text class="mb-3">Send your API key with every request.</flux:text>
                                <code class="block max-w-full overflow-x-auto whitespace-nowrap rounded-lg bg-zinc-950 px-3 py-3 font-mono text-xs text-zinc-300 sm:px-4 sm:text-sm">Authorization: Bearer &lt;your-api-key&gt;</code>
                            </div>
                        </li>
                        <li class="grid gap-3 py-5 md:grid-cols-[12rem_1fr] md:gap-8">
                            <flux:heading level="2">4. Make a request</flux:heading>
                            <div>
                                <pre class="max-w-full overflow-x-auto overscroll-x-contain rounded-lg bg-zinc-950 p-3 sm:p-4 font-mono text-xs text-zinc-300"><code><span class="text-(--color-accent)">curl</span> {{ $baseUrl }}/customers/customer-123 \\
  -H "Authorization: Bearer &lt;your-api-key&gt;" \\
  -H "Accept: application/json"</code></pre>
                                <flux:text size="sm" class="mt-3">The first request returns <code>"status": "created"</code>. Later requests return the same addresses with <code>"status": "existing"</code>.</flux:text>

                                <flux:callout variant="secondary" icon="circle-stack" class="mt-4">
                                    <flux:callout.heading>Store the addresses you receive</flux:callout.heading>
                                    <flux:callout.text>Save each deposit address with the customer in your database, then reuse it whenever you need to display the address. The API will return the same addresses again, but storing them avoids unnecessary requests and leaves more of your rate limit available.</flux:callout.text>
                                </flux:callout>
                            </div>
                        </li>
                    </ol>
                </flux:tab.panel>

                <flux:tab.panel name="limits" :selected="$activeTab === 'limits'" class="pt-6 sm:pt-8">
                    <div class="mb-6 flex items-start justify-between gap-6">
                        <flux:text>
                            @auth
                                These are the live values for your account. Processing a higher volume? <flux:link href="{{ route('support') }}" wire:navigate>Ask support</flux:link> about custom limits.
                            @else
                                These are the current standard values. Higher-volume accounts can request custom limits after <flux:link href="{{ route('signin') }}" wire:navigate>signing in</flux:link>.
                            @endauth
                        </flux:text>
                    </div>

                    <div class="divide-y divide-white/10 border-y border-white/10">
                        <section class="grid gap-3 py-5 md:grid-cols-[12rem_1fr] md:gap-8">
                            <div>
                                <flux:heading level="2">Deposit fee</flux:heading>
                                @if (auth()->user()?->role === 'owner' && ! auth()->user()?->is_admin)
                                    <flux:text size="sm" class="mt-1">Your account's deposit fee</flux:text>
                                @endif
                            </div>
                            <div>
                                <p class="font-ledger text-sm font-medium tabular-nums text-white">{{ number_format((float) $depositFee, 2) }}%</p>
                                <flux:text class="mt-1">@auth Your live rate. @else Current standard rate. @endauth Deducted before confirmed deposits are credited.</flux:text>
                            </div>
                        </section>

                        <section class="grid gap-4 py-5 md:grid-cols-[12rem_1fr] md:gap-8">
                            <flux:heading level="2">Minimum deposits</flux:heading>
                            <dl class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <dt><flux:text size="sm">Bitcoin</flux:text></dt>
                                    <dd class="mt-1 font-ledger text-sm font-medium tabular-nums text-white">{{ number_format((float) $settings->min_deposit_bitcoin, 8, '.', '') }} BTC</dd>
                                </div>
                                <div>
                                    <dt><flux:text size="sm">USDT (TRC20)</flux:text></dt>
                                    <dd class="mt-1 font-ledger text-sm font-medium tabular-nums text-white">{{ number_format((float) $settings->min_deposit_usdt_trc20, 2, '.', '') }} USDT</dd>
                                </div>
                                <div>
                                    <dt><flux:text size="sm">USDT (ERC20)</flux:text></dt>
                                    <dd class="mt-1 font-ledger text-sm font-medium tabular-nums text-white">{{ number_format((float) $settings->min_deposit_usdt_erc20, 2, '.', '') }} USDT</dd>
                                </div>
                            </dl>
                        </section>

                        <section class="grid gap-3 py-5 md:grid-cols-[12rem_1fr] md:gap-8">
                            <flux:heading level="2">API request limit</flux:heading>
                            <div>
                                <p class="font-ledger text-sm font-medium tabular-nums text-white">{{ $rateLimit }} requests per minute</p>
                                <flux:text class="mt-1">@auth Your live limit. @else Current standard limit. @endauth Each API key has its own counter.</flux:text>
                                <flux:text size="sm" class="mt-2">Successful responses include <code>X-RateLimit-Limit</code> and <code>X-RateLimit-Remaining</code>. Over the limit, the API returns <code>429 Too Many Requests</code> with <code>Retry-After</code>.</flux:text>
                            </div>
                        </section>
                    </div>
                </flux:tab.panel>

                <flux:tab.panel name="endpoints" :selected="$activeTab === 'endpoints'" class="pt-6 sm:pt-8">
                    @forelse ($endpoints as $group => $items)
                        <div class="mb-10">
                            <flux:heading size="xl" level="2" class="mb-4">{{ $group }}</flux:heading>

                            <div class="grid gap-6">
                                @foreach ($items as $endpoint)
                                    <article class="border-t border-white/10 py-5 first:border-t-0">
                                        <div class="flex flex-wrap items-start gap-2 sm:items-center sm:gap-3">
                                            <flux:badge color="{{ $endpoint['method'] === 'GET' ? 'blue' : 'emerald' }}" size="sm">
                                                {{ $endpoint['method'] }}
                                            </flux:badge>
                                            <flux:heading size="md" level="3" class="break-all font-mono text-sm sm:text-base">{{ str_replace('{reference}', 'customer-123', $endpoint['uri']) }}</flux:heading>
                                        </div>

                                        @if ($endpoint['description'])
                                            <flux:text class="mt-3">{{ $endpoint['description'] }}</flux:text>
                                        @endif

                                        @if ($endpoint['uri'] === '/api/v1/customers/{reference}' && $endpoint['method'] === 'GET')
                                            <div class="mt-4">
                                                <flux:heading size="sm" class="mb-2">Response</flux:heading>
                                                <pre class="overflow-hidden whitespace-pre-wrap break-words rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">status</span>": "created",
  "<span class="text-(--color-accent)">data</span>": {
    "<span class="text-(--color-accent)">customer_reference</span>": "customer-123",
    "<span class="text-(--color-accent)">addresses</span>": [
      { "<span class="text-(--color-accent)">network</span>": "bitcoin", "<span class="text-(--color-accent)">address</span>": "bc1q...", "<span class="text-(--color-accent)">qr</span>": "{{ url('/qr/bc1q...') }}", "<span class="text-(--color-accent)">minimum_deposit</span>": "{{ number_format((float) $settings->min_deposit_bitcoin, 8, '.', '') }}" },
      { "<span class="text-(--color-accent)">network</span>": "usdt_trc20", "<span class="text-(--color-accent)">address</span>": "T...", "<span class="text-(--color-accent)">qr</span>": "{{ url('/qr/T...') }}", "<span class="text-(--color-accent)">minimum_deposit</span>": "{{ number_format((float) $settings->min_deposit_usdt_trc20, 2, '.', '') }}" },
      { "<span class="text-(--color-accent)">network</span>": "usdt_erc20", "<span class="text-(--color-accent)">address</span>": "0x...", "<span class="text-(--color-accent)">qr</span>": "{{ url('/qr/0x...') }}", "<span class="text-(--color-accent)">minimum_deposit</span>": "{{ number_format((float) $settings->min_deposit_usdt_erc20, 2, '.', '') }}" }
    ]
  }
}</code></pre>
                                            <flux:text class="mt-2 text-sm"><code>status</code> is <code>created</code> on the first request (HTTP 201) and <code>existing</code> on later requests (HTTP 200). All addresses include a <code>qr</code> URL for the deposit address and a <code>minimum_deposit</code> in the network's native currency — deposits below this amount are not credited.</flux:text>
                                            </div>
                                        @endif

                                        @if ($endpoint['uri'] === '/api/v1/balances' && $endpoint['method'] === 'GET')
                                            <div class="mt-4">
                                                <flux:heading size="sm" class="mb-2">Response</flux:heading>
                                                <pre class="max-w-full overflow-hidden whitespace-pre-wrap break-words rounded-lg bg-zinc-950 p-3 font-mono text-xs text-zinc-300 sm:p-4"><code>{
  "<span class="text-(--color-accent)">balances</span>": [
    { "<span class="text-(--color-accent)">network</span>": "Bitcoin", "<span class="text-(--color-accent)">amount</span>": 0.50000000, "<span class="text-(--color-accent)">usd_value</span>": 12345.67 },
    { "<span class="text-(--color-accent)">network</span>": "USDT (TRC20)", "<span class="text-(--color-accent)">amount</span>": 100.00000000, "<span class="text-(--color-accent)">usd_value</span>": 100.00 },
    { "<span class="text-(--color-accent)">network</span>": "USDT (ERC20)", "<span class="text-(--color-accent)">amount</span>": 50.00000000, "<span class="text-(--color-accent)">usd_value</span>": 50.00 }
  ],
  "<span class="text-(--color-accent)">total_usd</span>": 12495.67,
  "<span class="text-(--color-accent)">last_updated_at</span>": "2026-08-27T12:00:00+07:00"
}</code></pre>
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <flux:text>No API endpoints are currently available.</flux:text>
                    @endforelse
                </flux:tab.panel>

                <flux:tab.panel name="webhooks" :selected="$activeTab === 'webhooks'" class="pt-6 sm:pt-8">
                    <div class="max-w-4xl border-y border-white/10 py-6">
                        <flux:heading size="2xl" level="2" class="mb-8">Webhooks</flux:heading>

                        <div class="space-y-8">
                            <div class="space-y-4">
                                <flux:text>
                                    When a deposit is credited, samedepo sends a signed POST request to the webhook URL you configure in your dashboard.
                                </flux:text>

                                <flux:text>
                                    Your endpoint must return any HTTP 2xx status code. The response body is ignored. If a test or real delivery does not receive a 2xx response, samedepo will notify you by email.
                                </flux:text>
                            </div>

                            <div>
                                <flux:heading size="md" class="mb-3">Request headers</flux:heading>
                                <pre class="max-w-full overflow-x-auto overscroll-x-contain rounded-lg bg-zinc-950 p-3 sm:p-4 text-xs font-mono text-zinc-300"><code><span class="text-(--color-accent)">X-Samedepo-Event</span>: deposit.credited
<span class="text-(--color-accent)">X-Samedepo-Signature</span>: &lt;hmac-sha256-hex&gt;
<span class="text-(--color-accent)">Content-Type</span>: application/json</code></pre>
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <flux:heading size="md" class="mb-3">Retry schedule</flux:heading>
                                    <flux:text>Up to 5 attempts with a 60s, 5m, 15m backoff. Failed deliveries are retried automatically.</flux:text>
                                </div>
                                <div>
                                    <flux:heading size="md" class="mb-3">Verification</flux:heading>
                                    <flux:text>Always verify the signature using your webhook secret before trusting the payload.</flux:text>
                                </div>
                            </div>

                            <div>
                                <flux:heading size="md" class="mb-3">Verify the signature</flux:heading>
                                <pre class="max-w-full overflow-x-auto overscroll-x-contain rounded-lg bg-zinc-950 p-3 sm:p-4 text-xs font-mono text-zinc-300"><code>$payload = file_get_contents(<span class="text-(--color-accent)">'php://input'</span>);
$expected = hash_hmac(<span class="text-(--color-accent)">'sha256'</span>, $payload, $yourSecret);

if (! hash_equals($expected, $_SERVER['HTTP_X_SAMEDEPo_SIGNATURE'] ?? '')) {
    http_response_code(<span class="text-(--color-accent)">401</span>);
    exit(<span class="text-(--color-accent)">'Invalid signature'</span>);
}</code></pre>
                            </div>

                            <div>
                                <flux:heading size="md" class="mb-3">Example: deposit.credited</flux:heading>
                                <flux:text class="mb-3">
                                    <code>credited_usd_value</code> is the USD value of <code>credited_amount</code> at the latest stored conversion rate for the deposit network.
                                </flux:text>
                                <pre class="max-w-full overflow-x-auto overscroll-x-contain rounded-lg bg-zinc-950 p-3 sm:p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">event</span>": "deposit.credited",
  "<span class="text-(--color-accent)">id</span>": "6a3f...",
  "<span class="text-(--color-accent)">created_at</span>": "2026-08-27T12:00:00+07:00",
  "<span class="text-(--color-accent)">data</span>": {
    "<span class="text-(--color-accent)">id</span>": 1,
    "<span class="text-(--color-accent)">customer_id</span>": 1,
    "<span class="text-(--color-accent)">network</span>": "bitcoin",
    "<span class="text-(--color-accent)">tx_hash</span>": "abc123...",
    "<span class="text-(--color-accent)">gross_amount</span>": "0.10000000",
    "<span class="text-(--color-accent)">fee_amount</span>": "0.00050000",
    "<span class="text-(--color-accent)">credited_amount</span>": "0.09950000",
    "<span class="text-(--color-accent)">credited_usd_value</span>": "2985.00",
    "<span class="text-(--color-accent)">status</span>": "credited",
    "<span class="text-(--color-accent)">credited_at</span>": "2026-08-27T12:00:00+07:00"
  }
}</code></pre>
                            </div>
                        </div>
                    </div>
                </flux:tab.panel>
            </flux:tab.group>
        </div>
    </section>
</x-layouts.public>
