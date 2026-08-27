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

                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>Method</flux:table.column>
                                    <flux:table.column>Endpoint</flux:table.column>
                                    <flux:table.column>Description</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @foreach ($items as $endpoint)
                                        <flux:table.row>
                                            <flux:table.cell class="py-0">
                                                <flux:badge color="{{ $endpoint['method'] === 'GET' ? 'blue' : 'emerald' }}" size="sm">
                                                    {{ $endpoint['method'] }}
                                                </flux:badge>
                                            </flux:table.cell>
                                            <flux:table.cell class="font-mono text-sm">{{ $endpoint['uri'] }}</flux:table.cell>
                                            <flux:table.cell>{{ $endpoint['description'] ?: '—' }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    @empty
                        <flux:text>No API endpoints are currently available.</flux:text>
                    @endforelse
                </flux:tab.panel>

                <flux:tab.panel name="webhooks" class="pt-8">
                    <flux:card>
                        <flux:heading size="lg" level="2" class="mb-4">Receiving webhook events</flux:heading>

                        <div class="space-y-4">
                            <flux:text>
                                Configure a webhook endpoint in your dashboard to receive real-time transaction events. Each delivery is a signed POST request.
                            </flux:text>

                            <div>
                                <flux:text class="font-medium">Supported event types</flux:text>
                                <ul class="mt-1 list-inside list-disc text-zinc-400">
                                    <li>deposit.credited</li>
                                    <li>withdrawal.status (all withdrawal status transitions)</li>
                                </ul>
                            </div>

                            <div>
                                <flux:text class="font-medium">Signature header</flux:text>
                                <code class="block mt-1 rounded-lg bg-zinc-900 px-4 py-3 text-sm font-mono text-zinc-100">
                                    X-Signature: &lt;hmac-sha256-hex&gt;
                                </code>
                            </div>
                        </div>
                    </flux:card>
                </flux:tab.panel>
            </flux:tab.group>
        </div>
    </section>
</x-layouts.public>
