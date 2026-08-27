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
                                <flux:link href="{{ route('signin') }}" wire:navigate>API Keys</flux:link>
                                section to create a key. Keep it secret.
                            </flux:text>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">2. Base URL</flux:heading>
                            <code class="block rounded-lg bg-zinc-900 px-4 py-3 text-sm font-mono text-zinc-100">
                                {{ $baseUrl }}
                            </code>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">3. Authentication</flux:heading>
                            <flux:text class="mb-3">Send your API key in the Authorization header on every request.</flux:text>
                            <code class="block rounded-lg bg-zinc-900 px-4 py-3 text-sm font-mono text-zinc-100">
                                Authorization: Bearer &lt;your-api-key&gt;
                            </code>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">4. Example request</flux:heading>
                            <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>curl -X POST {{ $baseUrl }}/customers \\
  -H "Authorization: Bearer &lt;your-api-key&gt;" \\
  -H "Accept: application/json" \\
  -d '{"reference": "customer-123"}'</code></pre>
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
                                                    <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>{
  "reference": "customer-123"
}</code></pre>
                                                </div>
                                                <div>
                                                    <flux:heading size="sm" class="mb-2">Response (200 existing, 201 created)</flux:heading>
                                                    <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>{
  "customer_reference": "customer-123",
  "addresses": [
    { "network": "bitcoin", "address": "bc1q..." },
    { "network": "usdt_trc20", "address": "T..." },
    { "network": "usdt_erc20", "address": "0x..." }
  ]
}</code></pre>
                                                </div>
                                            </div>
                                        @endif

                                        @if ($endpoint['uri'] === '/api/v1/customers/{reference}' && $endpoint['method'] === 'GET')
                                            <div class="mt-4">
                                                <flux:heading size="sm" class="mb-2">Response</flux:heading>
                                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>{
  "customer_reference": "customer-123",
  "addresses": [
    { "network": "bitcoin", "address": "bc1q..." },
    { "network": "usdt_trc20", "address": "T..." },
    { "network": "usdt_erc20", "address": "0x..." }
  ]
}</code></pre>
                                            </div>
                                        @endif

                                        @if ($endpoint['uri'] === '/api/v1/balances' && $endpoint['method'] === 'GET')
                                            <div class="mt-4">
                                                <flux:heading size="sm" class="mb-2">Response</flux:heading>
                                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>{
  "balances": [
    { "network": "Bitcoin", "amount": 0.50000000, "usd_value": 12345.67 },
    { "network": "USDT (TRC20)", "amount": 100.00000000, "usd_value": 100.00 },
    { "network": "USDT (ERC20)", "amount": 50.00000000, "usd_value": 50.00 }
  ],
  "total_usd": 12495.67,
  "last_updated_at": "2026-08-27T12:00:00+07:00"
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
                    <div class="grid gap-6 md:grid-cols-2">
                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">How webhooks are delivered</flux:heading>

                            <div class="space-y-4">
                                <flux:text>
                                    When a deposit is credited or a withdrawal status changes, samedepo sends a signed POST request to the webhook URL you configure in your dashboard.
                                </flux:text>

                                <div>
                                    <flux:text class="font-medium">Request headers</flux:text>
                                    <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>X-Samedepo-Event: deposit.credited
X-Samedepo-Signature: &lt;hmac-sha256-hex&gt;
Content-Type: application/json</code></pre>
                                </div>

                                <div>
                                    <flux:text class="font-medium">Retry schedule</flux:text>
                                    <flux:text>Up to 5 attempts with a 60s, 5m, 15m backoff. Failed deliveries are retried automatically.</flux:text>
                                </div>
                            </div>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">Verify the signature</flux:heading>

                            <div class="space-y-4">
                                <flux:text>Always verify the signature using your webhook secret before trusting the payload.</flux:text>

                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>$payload = file_get_contents('php://input');
$expected = hash_hmac('sha256', $payload, $yourSecret);

if (! hash_equals($expected, $_SERVER['HTTP_X_SAMEDEPo_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit('Invalid signature');
}</code></pre>
                            </div>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">Example: deposit.credited</flux:heading>

                            <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>{
  "event": "deposit.credited",
  "id": "6a3f...",
  "created_at": "2026-08-27T12:00:00+07:00",
  "data": {
    "id": 1,
    "customer_id": 1,
    "network": "bitcoin",
    "tx_hash": "abc123...",
    "gross_amount": "0.10000000",
    "fee_amount": "0.00050000",
    "credited_amount": "0.09950000",
    "status": "credited",
    "credited_at": "2026-08-27T12:00:00+07:00"
  }
}</code></pre>
                        </flux:card>

                        <flux:card>
                            <flux:heading size="lg" level="2" class="mb-4">Example: withdrawal.status</flux:heading>

                            <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>{
  "event": "withdrawal.status",
  "id": "7b8e...",
  "created_at": "2026-08-27T12:00:00+07:00",
  "data": {
    "id": 1,
    "network": "usdt_trc20",
    "gross_amount": "150.00000000",
    "network_fee": "1.00000000",
    "amount_sent": "149.00000000",
    "destination_address": "T...",
    "mode": "instant",
    "status": "broadcasted",
    "tx_hash": "def456...",
    "decided_at": "2026-08-27T11:50:00+07:00",
    "sent_at": "2026-08-27T11:55:00+07:00"
  }
}</code></pre>
                        </flux:card>
                    </div>
                </flux:tab.panel>
            </flux:tab.group>
        </div>
    </section>
</x-layouts.public>
