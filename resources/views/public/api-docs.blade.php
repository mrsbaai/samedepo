<x-layouts.public :title="'API Documentation'" :description="'Live, automatically-generated reference for the samedepo integration API.'">
    <section class="py-12">
        <div class="max-w-5xl mx-auto px-6">
            <flux:heading size="xl" level="1">API Documentation</flux:heading>
            <flux:text size="lg" class="mt-4 text-zinc-400">
                Live reference for the samedepo integration API. This page updates automatically as endpoints change.
            </flux:text>

            <div class="mt-12 grid gap-8">
                <flux:card>
                    <flux:heading size="lg" level="2" class="mb-4">Getting started</flux:heading>

                    <div class="space-y-4">
                        <div>
                            <flux:text class="font-medium">Base URL</flux:text>
                            <code class="block mt-1 rounded bg-zinc-900 px-3 py-2 text-sm font-mono text-zinc-100">
                                {{ $spec['servers'][0]['url'] ?? url('/api') }}
                            </code>
                        </div>

                        <div>
                            <flux:text class="font-medium">Version</flux:text>
                            <flux:text>{{ $spec['info']['version'] ?? '1.0.0' }}</flux:text>
                        </div>

                        <div>
                            <flux:text class="font-medium">Authentication</flux:text>
                            <flux:text>Include an API key in the Authorization header.</flux:text>
                            <code class="block mt-1 rounded bg-zinc-900 px-3 py-2 text-sm font-mono text-zinc-100">
                                Authorization: Bearer &lt;your-api-key&gt;
                            </code>
                        </div>
                    </div>
                </flux:card>

                <div>
                    <flux:heading size="lg" level="2" class="mb-4">Endpoints</flux:heading>

                    @if (empty($spec['paths']))
                        <flux:text>No endpoints are currently documented.</flux:text>
                    @else
                        <flux:accordion>
                            @foreach ($spec['paths'] as $path => $methods)
                                @foreach ($methods as $method => $operation)
                                    <flux:accordion.item>
                                        <flux:accordion.heading>
                                            <div class="flex items-center gap-3">
                                                <flux:badge color="{{ $method === 'get' ? 'blue' : 'green' }}" size="sm">
                                                    {{ strtoupper($method) }}
                                                </flux:badge>
                                                <span class="font-mono text-sm">{{ $path }}</span>
                                            </div>
                                        </flux:accordion.heading>

                                        <flux:accordion.content>
                                            <div class="space-y-4">
                                                @if (! empty($operation['summary']))
                                                    <flux:heading size="sm">{{ $operation['summary'] }}</flux:heading>
                                                @endif

                                                @if (! empty($operation['description']))
                                                    <flux:heading>{{ $operation['description'] }}</flux:heading>
                                                @endif

                                                <pre class="overflow-auto rounded-lg bg-zinc-950 p-4 text-xs font-mono text-zinc-100"><code>{!! json_encode($operation, JSON_PRETTY_PRINT) !!}</code></pre>
                                            </div>
                                        </flux:accordion.content>
                                    </flux:accordion.item>
                                @endforeach
                            @endforeach
                        </flux:accordion>
                    @endif
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
