<div class="py-8">
    <div class="mb-6">
        <flux:heading size="xl">API Keys</flux:heading>
        <flux:subheading class="mt-2">Generate and manage keys used to authenticate API requests from your integrations.</flux:subheading>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load API keys">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:skeleton class="h-9 w-64 mb-6" />
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column class="max-md:hidden">Status</flux:table.column>
                <flux:table.column class="max-md:hidden">Created</flux:table.column>
                <flux:table.column class="max-md:hidden">Last used</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-32" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-16" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell class="max-md:hidden"><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        @if ($successMessage)
            <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" class="mb-6" />
        @endif

        @if ($revealedKey)
            <div class="mb-6 space-y-4">
                <flux:callout variant="warning" icon="exclamation-triangle" heading="Copy your key now">
                    <flux:callout.text>This is the only time you'll see the full key.</flux:callout.text>
                </flux:callout>
                <flux:input icon="key" :value="$revealedKey" readonly copyable class="font-ledger" />
            </div>
        @endif

        <div class="mb-6">
            <div class="flex items-end gap-3">
                <flux:input wire:model="newKeyName" label="Key name" placeholder="e.g. Production website" class="w-full max-w-sm" />
                <flux:button wire:click="generate" variant="primary" icon="plus">Generate API Key</flux:button>
            </div>
            <flux:error name="newKeyName" class="mt-2" />
        </div>

        @if ($this->keys->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="key" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">No API keys yet. Generate a key to authenticate requests from your integration.</flux:text>
            </div>
        @else
            <flux:table :paginate="$this->keys" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column class="max-md:hidden">Status</flux:table.column>
                    <flux:table.column class="max-md:hidden">Created</flux:table.column>
                    <flux:table.column class="max-md:hidden">Last used</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->keys as $key)
                        <flux:table.row wire:key="key-{{ $key->id }}">
                            <flux:table.cell variant="strong">{{ $key->name }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden">
                                <flux:badge size="sm" color="{{ $key->status === 'active' ? 'green' : 'zinc' }}">{{ ucfirst($key->status) }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden whitespace-nowrap">
                                <flux:tooltip content="{{ $key->created_at->format('M j, Y H:i') }} UTC">
                                    <span>{{ $key->created_at->diffForHumans() }}</span>
                                </flux:tooltip>
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden whitespace-nowrap">
                                {{ $key->last_used_at ? $key->last_used_at->diffForHumans() : 'Never' }}
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                <div class="flex items-center gap-2">
                                    <flux:button variant="primary" size="sm" wire:click="confirmReplace({{ $key->id }})">Replace Key</flux:button>
                                    <flux:button variant="ghost" size="sm" wire:click="confirmRevoke({{ $key->id }})">Revoke Key</flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif

    <flux:modal wire:model.self="showRevokeModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Revoke API key?</flux:heading>
                <flux:text class="mt-2">This immediately stops this key from authenticating requests. This can't be undone.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="revoke">Revoke Key</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showReplaceModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Replace API key?</flux:heading>
                <flux:text class="mt-2">This revokes the current key and issues a new one. Any integration still using the old key will stop working.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="replace">Replace Key</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
