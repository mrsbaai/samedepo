<x-layouts.public :title="'API Documentation'" :description="'Integrate samedepo with our live, automatically-generated API reference.'">
    <section class="py-12">
        <div class="max-w-5xl mx-auto px-6">
            <flux:heading size="2xl" level="1" class="mb-2">API Documentation</flux:heading>
            <flux:text size="lg" class="text-zinc-400">
                Everything you need to integrate samedepo. The endpoint list below updates automatically as the API changes.
            </flux:text>

            <flux:tab.group class="mt-10" findable>
                <flux:tabs variant="pills">
                    <flux:tab name="quick-start" icon="bolt" selected>Quick start</flux:tab>
                    <flux:tab name="endpoints" icon="code-bracket">Endpoints</flux:tab>
                    <flux:tab name="webhooks" icon="signal">Webhooks</flux:tab>
                </flux:tabs>

                <flux:tab.panel name="quick-start" selected class="pt-8">
                    <div class="grid gap-6 md:grid-cols-2">
                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">1. Get an API key</flux:heading>
                            <flux:text>
                                Sign in to your owner account and visit the
                                <flux:link href="{{ route('api-keys') }}" wire:navigate>API Keys</flux:link>
                                section to create a key. Keep it secret.
                            </flux:text>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">2. Base URL</flux:heading>
                            <code class="block rounded-lg bg-zinc-900 px-4 py-3 text-sm font-mono text-zinc-300">
                                {{ $baseUrl }}
                            </code>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">3. Authentication</flux:heading>
                            <flux:text class="mb-3">Send your API key in the Authorization header on every request.</flux:text>
                            <code class="block rounded-lg bg-zinc-900 px-4 py-3 text-sm font-mono text-zinc-300">
                                Authorization: Bearer &lt;your-api-key&gt;
                            </code>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">4. Example request</flux:heading>
                            <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code><span class="text-(--color-accent)">curl</span> -X POST {{ $baseUrl }}/customers \\
  -H "Authorization: Bearer &lt;your-api-key&gt;" \\
  -H "Accept: application/json" \\
  -d '{"reference": "customer-123"}'</code></pre>
                        </flux:card>

                        <flux:card class="md:col-span-2">
                            <flux:heading size="lg" level="2" class="mb-4">5. Rate limits</flux:heading>
                            <flux:text class="mb-3">
                                Each API key is limited to <strong>{{ $rateLimit }} requests per minute</strong>, applied independently per key. Successful responses include <code>X-RateLimit-Limit</code> and <code>X-RateLimit-Remaining</code> headers. If you exceed the limit, the API returns HTTP <code>429 Too Many Requests</code> with a <code>Retry-After</code> header. Back off and retry after the number of seconds indicated by <code>Retry-After</code>.
                            </flux:text>
                            <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code>HTTP/1.1 <span class="text-(--color-accent)">429 Too Many Requests</span>
X-RateLimit-Limit: {{ $rateLimit }}
X-RateLimit-Remaining: 0
Retry-After: 47

{
  "message": "API rate limit exceeded. Please retry after the time indicated by the Retry-After header."
}</code></pre>
                        </flux:card>
                    </div>
                </flux:tab.panel>

                <flux:tab.panel name="endpoints" class="pt-8">
                    @forelse ($endpoints as $group => $items)
                        <div class="mb-10">
                            <flux:heading size="xl" level="2" class="mb-4">{{ $group }}</flux:heading>

                            <div class="grid gap-6">
                                @foreach ($items as $endpoint)
                                    <flux:card>
                                        <div class="flex flex-wrap items-center gap-3">
                                            <flux:badge color="{{ $endpoint['method'] === 'GET' ? 'blue' : 'emerald' }}" size="sm">
                                                {{ $endpoint['method'] }}
                                            </flux:badge>
                                            <flux:heading size="md" level="3" class="font-mono text-base">{{ $endpoint['uri'] }}</flux:heading>
                                        </div>

                                        @if ($endpoint['description'])
                                            <flux:text class="mt-3">{{ $endpoint['description'] }}</flux:text>
                                        @endif

                                        @if ($endpoint['uri'] === '/api/v1/customers' && $endpoint['method'] === 'POST')
                                            <div class="mt-4 grid gap-4">
                                                <div>
                                                    <flux:heading size="sm" class="mb-2">Request body</flux:heading>
                                                    <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">reference</span>": "customer-123"
}</code></pre>
                                                </div>
                                                <div>
                                                    <flux:heading size="sm" class="mb-2">Response (201 created / 200 existing)</flux:heading>
                                                    <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">status</span>": "created",
  "<span class="text-(--color-accent)">data</span>": {
    "<span class="text-(--color-accent)">customer_reference</span>": "customer-123",
    "<span class="text-(--color-accent)">addresses</span>": [
      { "<span class="text-(--color-accent)">network</span>": "bitcoin", "<span class="text-(--color-accent)">address</span>": "bc1q..." },
      { "<span class="text-(--color-accent)">network</span>": "usdt_trc20", "<span class="text-(--color-accent)">address</span>": "T..." },
      { "<span class="text-(--color-accent)">network</span>": "usdt_erc20", "<span class="text-(--color-accent)">address</span>": "0x..." }
    ]
  }
}</code></pre>
                                                    <flux:text class="mt-2 text-sm"><code>status</code> is <code>created</code> on first registration (HTTP 201) and <code>existing</code> when the owner/reference is already registered (HTTP 200).</flux:text>
                                                </div>
                                            </div>
                                        @endif

                                        @if ($endpoint['uri'] === '/api/v1/customers/{reference}' && $endpoint['method'] === 'GET')
                                            <div class="mt-4">
                                                <flux:heading size="sm" class="mb-2">Response</flux:heading>
                                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code>{
  "<span class="text-(--color-accent)">customer_reference</span>": "customer-123",
  "<span class="text-(--color-accent)">addresses</span>": [
    { "<span class="text-(--color-accent)">network</span>": "bitcoin", "<span class="text-(--color-accent)">address</span>": "bc1q..." },
    { "<span class="text-(--color-accent)">network</span>": "usdt_trc20", "<span class="text-(--color-accent)">address</span>": "T..." },
    { "<span class="text-(--color-accent)">network</span>": "usdt_erc20", "<span class="text-(--color-accent)">address</span>": "0x..." }
  ]
}</code></pre>
                                            </div>
                                        @endif

                                        @if ($endpoint['uri'] === '/api/v1/balances' && $endpoint['method'] === 'GET')
                                            <div class="mt-4">
                                                <flux:heading size="sm" class="mb-2">Response</flux:heading>
                                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code>{
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
                                    </flux:card>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <flux:text>No API endpoints are currently available.</flux:text>
                    @endforelse
                </flux:tab.panel>

                <flux:tab.panel name="webhooks" class="pt-8">
                    <flux:card class="max-w-4xl">
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
                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code><span class="text-(--color-accent)">X-Samedepo-Event</span>: deposit.credited
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
                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code>$payload = file_get_contents(<span class="text-(--color-accent)">'php://input'</span>);
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
                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-300"><code>{
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
                    </flux:card>
                </flux:tab.panel>
            </flux:tab.group>
        </div>
    </section>
</x-layouts.public>
