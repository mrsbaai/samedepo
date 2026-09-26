<x-layouts.public :title="'API Documentation'" :description="'Integrate samedepo with our live, automatically-generated API reference.'">
    @php
        $activeTab = in_array(request('tab'), ['limits', 'endpoints', 'webhooks'], true) ? request('tab') : 'quick-start';
        $tabs = [
            'quick-start' => ['Quick start', 'bolt'],
            'limits' => ['Limits & fees', 'adjustments-horizontal'],
            'endpoints' => ['Endpoint', 'code-bracket'],
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
                            No hidden fees.
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
                                @foreach ($networks as $meta)
                                    <div>
                                        <dt><flux:text size="sm">{{ $meta['label'] }}</flux:text></dt>
                                        <dd class="mt-1 font-ledger text-sm font-medium tabular-nums text-white">{{ number_format((float) $meta['min_deposit'], $meta['decimals'], '.', '') }} {{ $meta['symbol'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>

                        <section class="grid gap-4 py-5 md:grid-cols-[12rem_1fr] md:gap-8">
                            <flux:heading level="2">Confirmations</flux:heading>
                            <dl class="grid gap-4 md:grid-cols-3">
                                @foreach ($networks as $meta)
                                    <div>
                                        <dt><flux:text size="sm">{{ $meta['label'] }}</flux:text></dt>
                                        <dd class="mt-1 font-ledger text-sm font-medium tabular-nums text-white">{{ $meta['confirmations'] }} confirmations</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <flux:text size="sm" class="md:col-start-2">A deposit is credited once it reaches the required confirmations for its network.</flux:text>
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
      @foreach ($networks as $key => $meta){ "<span class="text-(--color-accent)">network</span>": "{{ $key }}", "<span class="text-(--color-accent)">address</span>": "{{ $meta['example_address'] }}", "<span class="text-(--color-accent)">qr</span>": "{{ url('/qr/'.$meta['example_address']) }}", "<span class="text-(--color-accent)">minimum_deposit</span>": "{{ number_format((float) $meta['min_deposit'], $meta['decimals'], '.', '') }}" }@if (!$loop->last),
      @endif
      @endforeach
    ]
  }
}</code></pre>
                                            <flux:text class="mt-2 text-sm"><code>status</code> is <code>created</code> on the first request (HTTP 201) and <code>existing</code> on later requests (HTTP 200). All addresses include a <code>qr</code> URL for the deposit address and a <code>minimum_deposit</code> in the network's native currency. Payments below the minimum are held; payments to the same address on the same network add up and are all credited (normal fee, no extra charge) once the total reaches the minimum. If the minimum isn't reached within 7 days of the first short payment, those payments expire and are not credited.</flux:text>
                                            <flux:text class="mt-2 text-sm">Every EVM asset shares one <code>0x</code> deposit address per customer — a single address accepts all enabled ERC-20 and BEP-20 networks.</flux:text>
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <flux:text>No API endpoint is currently available.</flux:text>
                    @endforelse
                </flux:tab.panel>

                <flux:tab.panel name="webhooks" :selected="$activeTab === 'webhooks'" class="pt-6 sm:pt-8">
                    <div class="max-w-4xl border-y border-white/10 py-6">
                        <flux:heading size="2xl" level="2" class="mb-8">Webhooks</flux:heading>

                        <div class="space-y-8">
                            <div class="space-y-4">
                                <flux:text>
                                    When a deposit is first detected and when it is credited, samedepo sends a signed POST request to the webhook URL you configure in your dashboard.
                                </flux:text>

                                <flux:text>
                                    Your endpoint must return any HTTP 2xx status code. The response body is ignored. If a test or real delivery does not receive a 2xx response, samedepo will notify you by email.
                                </flux:text>
                            </div>

                            <div>
                                <flux:heading size="md" class="mb-3">Event types and lifecycle</flux:heading>
                                <flux:text class="mb-3">
                                    <code>deposit.pending</code> is sent the first time we see an inbound payment, regardless of confirmation count. <code>deposit.credited</code> is sent once the required network confirmations have been reached and the deposit has been credited.
                                </flux:text>
                                <flux:text class="mb-3">
                                    <code>deposit.below_minimum</code> is sent when a confirmed payment falls below the network minimum and is held as a short payment. <code>deposit.forfeited</code> is sent when a short payment is not topped up to the minimum within 7 days of the first short payment and expires.
                                </flux:text>
                                <flux:text class="mb-3">
                                    <code>network</code> is one of the enabled network keys (@foreach (array_keys($networks) as $key)<code>{{ $key }}</code>@if (!$loop->last), @endif@endforeach). Required confirmations are configured per network.
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

if (! hash_equals($expected, $_SERVER['HTTP_X_SAMEDEPO_SIGNATURE'] ?? '')) {
    http_response_code(<span class="text-(--color-accent)">401</span>);
    exit(<span class="text-(--color-accent)">'Invalid signature'</span>);
}</code></pre>
                            </div>

                            <div>
                                <flux:heading size="md" class="mb-3">Example: deposit.pending</flux:heading>
                                <flux:text class="mb-3">
                                    <code>gross_amount_usd</code> is the USD value of <code>gross_amount</code> at the latest stored conversion rate. <code>confirmations_required</code> is the configured threshold for the deposit's network before it can be credited.
                                </flux:text>
                                <pre class="max-w-full overflow-x-auto overscroll-x-contain rounded-lg bg-zinc-950 p-3 sm:p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">event</span>": "deposit.pending",
  "<span class="text-(--color-accent)">id</span>": "6a3f...",
  "<span class="text-(--color-accent)">created_at</span>": "2026-08-27T12:00:00+07:00",
  "<span class="text-(--color-accent)">data</span>": {
    "<span class="text-(--color-accent)">id</span>": 1,
    "<span class="text-(--color-accent)">customer_id</span>": 1,
    "<span class="text-(--color-accent)">customer_reference</span>": "customer-123",
    "<span class="text-(--color-accent)">network</span>": "bitcoin",
    "<span class="text-(--color-accent)">tx_hash</span>": "abc123...",
    "<span class="text-(--color-accent)">gross_amount</span>": "0.10000000",
    "<span class="text-(--color-accent)">gross_amount_usd</span>": "3000.00",
    "<span class="text-(--color-accent)">minimum_deposit</span>": "0.00100000",
    "<span class="text-(--color-accent)">meets_minimum</span>": true,
    "<span class="text-(--color-accent)">status</span>": "pending",
    "<span class="text-(--color-accent)">confirmation_count</span>": 2,
    "<span class="text-(--color-accent)">confirmations_required</span>": 3,
    "<span class="text-(--color-accent)">detected_at</span>": "2026-08-27T12:00:00+07:00"
  }
}</code></pre>
                            </div>

                            <div>
                                <flux:heading size="md" class="mb-3">Example: deposit.credited</flux:heading>
                                <flux:text class="mb-3">
                                    <code>gross_amount_usd</code> is the USD value of <code>gross_amount</code> at the latest stored conversion rate. <code>credited_amount</code> and <code>credited_usd_value</code> are not included in the payload; use <code>gross_amount</code> and <code>gross_amount_usd</code>.
                                </flux:text>
                                <pre class="max-w-full overflow-x-auto overscroll-x-contain rounded-lg bg-zinc-950 p-3 sm:p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">event</span>": "deposit.credited",
  "<span class="text-(--color-accent)">id</span>": "6a3f...",
  "<span class="text-(--color-accent)">created_at</span>": "2026-08-27T12:00:00+07:00",
  "<span class="text-(--color-accent)">data</span>": {
    "<span class="text-(--color-accent)">id</span>": 1,
    "<span class="text-(--color-accent)">customer_id</span>": 1,
    "<span class="text-(--color-accent)">customer_reference</span>": "customer-123",
    "<span class="text-(--color-accent)">network</span>": "bitcoin",
    "<span class="text-(--color-accent)">tx_hash</span>": "abc123...",
    "<span class="text-(--color-accent)">gross_amount</span>": "0.10000000",
    "<span class="text-(--color-accent)">gross_amount_usd</span>": "3000.00",
    "<span class="text-(--color-accent)">status</span>": "credited",
    "<span class="text-(--color-accent)">credited_at</span>": "2026-08-27T12:00:00+07:00"
  }
}</code></pre>
                            </div>

                            <div>
                                <flux:heading size="md" class="mb-3">Example: deposit.below_minimum</flux:heading>
                                <flux:text class="mb-3">
                                    <code>minimum_deposit</code> is the effective minimum for the deposit address. <code>received_total</code> is the sum of open short payments on the same address, and <code>amount_needed</code> is the remaining amount required to reach the minimum. Payments to the same address on the same network add up and are all credited together once the total reaches the minimum; <code>expires_at</code> is the deadline.
                                </flux:text>
                                <pre class="max-w-full overflow-x-auto overscroll-x-contain rounded-lg bg-zinc-950 p-3 sm:p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">event</span>": "deposit.below_minimum",
  "<span class="text-(--color-accent)">id</span>": "6a3f...",
  "<span class="text-(--color-accent)">created_at</span>": "2026-08-27T12:00:00+07:00",
  "<span class="text-(--color-accent)">data</span>": {
    "<span class="text-(--color-accent)">id</span>": 1,
    "<span class="text-(--color-accent)">customer_id</span>": 1,
    "<span class="text-(--color-accent)">customer_reference</span>": "customer-123",
    "<span class="text-(--color-accent)">network</span>": "bitcoin",
    "<span class="text-(--color-accent)">tx_hash</span>": "abc123...",
    "<span class="text-(--color-accent)">gross_amount</span>": "0.00040000",
    "<span class="text-(--color-accent)">gross_amount_usd</span>": "12.00",
    "<span class="text-(--color-accent)">status</span>": "below_minimum",
    "<span class="text-(--color-accent)">minimum_deposit</span>": "0.00100000",
    "<span class="text-(--color-accent)">received_total</span>": "0.00040000",
    "<span class="text-(--color-accent)">amount_needed</span>": "0.00060000",
    "<span class="text-(--color-accent)">expires_at</span>": "2026-09-03T12:00:00+07:00"
  }
}</code></pre>
                            </div>

                            <div>
                                <flux:heading size="md" class="mb-3">Example: deposit.forfeited</flux:heading>
                                <flux:text class="mb-3">
                                    <code>forfeited_at</code> is when the short payment expired. Expired payments are not credited and cannot be topped up; a new payment to the address starts a fresh window.
                                </flux:text>
                                <pre class="max-w-full overflow-x-auto overscroll-x-contain rounded-lg bg-zinc-950 p-3 sm:p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">event</span>": "deposit.forfeited",
  "<span class="text-(--color-accent)">id</span>": "6a3f...",
  "<span class="text-(--color-accent)">created_at</span>": "2026-09-03T12:00:00+07:00",
  "<span class="text-(--color-accent)">data</span>": {
    "<span class="text-(--color-accent)">id</span>": 1,
    "<span class="text-(--color-accent)">customer_id</span>": 1,
    "<span class="text-(--color-accent)">customer_reference</span>": "customer-123",
    "<span class="text-(--color-accent)">network</span>": "bitcoin",
    "<span class="text-(--color-accent)">tx_hash</span>": "abc123...",
    "<span class="text-(--color-accent)">gross_amount</span>": "0.00040000",
    "<span class="text-(--color-accent)">gross_amount_usd</span>": "12.00",
    "<span class="text-(--color-accent)">status</span>": "forfeited",
    "<span class="text-(--color-accent)">forfeited_at</span>": "2026-09-03T12:00:00+07:00"
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
