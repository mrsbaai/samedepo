<div class="py-8">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">API Keys</flux:heading>
            <flux:subheading class="mt-2">Keys authenticate API requests from your integration.</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">Create key</flux:button>
    </div>

    @if ($this->uiState === 'error')
        <flux:callout variant="danger" icon="x-circle" heading="Couldn't load API keys">
            <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($this->uiState === 'loading')
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Key</flux:table.column>
                <flux:table.column>Created</flux:table.column>
                <flux:table.column>Last used</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (range(1, 5) as $r)
                    <flux:table.row>
                        <flux:table.cell><flux:skeleton class="h-4 w-32" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-24" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-20" /></flux:table.cell>
                        <flux:table.cell><flux:skeleton class="h-4 w-8" /></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        @if ($successMessage)
            <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" class="mb-6" />
        @endif

        @if ($revealedKey)
            <flux:callout variant="warning" icon="exclamation-triangle" heading="Copy your key now" class="mb-6">
                <flux:callout.text>This is the only time you'll see the full key.</flux:callout.text>
                <div class="mt-3">
                    <flux:input icon="key" :value="$revealedKey" readonly copyable class="font-ledger" />
                </div>
            </flux:callout>
        @endif

        @if ($this->keys->isEmpty())
            <div class="py-12 text-center">
                <flux:icon icon="key" variant="outline" class="mx-auto h-8 w-8 text-zinc-400" />
                <flux:text class="mt-3">No API keys yet. Create a key to authenticate requests from your integration.</flux:text>
            </div>
        @else
            <flux:table :paginate="$this->keys" pagination:scroll-to>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Key</flux:table.column>
                    <flux:table.column class="max-md:hidden">Created</flux:table.column>
                    <flux:table.column class="max-md:hidden">Last used</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->keys as $key)
                        <flux:table.row wire:key="key-{{ $key->id }}">
                            <flux:table.cell variant="strong">{{ $key->name }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($key->key_prefix)
                                    <flux:badge size="sm" class="font-ledger">{{ $key->key_prefix }}…</flux:badge>
                                @else
                                    <span class="text-zinc-400">&mdash;</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden whitespace-nowrap">
                                {{ \App\Support\Dates::humanFlat($key->created_at) }}
                            </flux:table.cell>
                            <flux:table.cell class="max-md:hidden whitespace-nowrap">
                                {{ $key->last_used_at ? \App\Support\Dates::humanFlat($key->last_used_at) : 'Never' }}
                            </flux:table.cell>
                            <flux:table.cell class="py-0">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                    <flux:menu class="dark">
                                        <flux:menu.item icon="arrow-path" wire:click="confirmReplace({{ $key->id }})">Replace key</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmRevoke({{ $key->id }})">Revoke key</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif

    <flux:modal wire:model.self="showCreateModal" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Create API key</flux:heading>
                <flux:text class="mt-2">Name the key after the integration that will use it.</flux:text>
            </div>
            <flux:input wire:model="newKeyName" label="Key name" placeholder="e.g. Production website" />
            <flux:error name="newKeyName" />
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="generate">Create key</flux:button>
            </div>
        </div>
    </flux:modal>

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
                <flux:button variant="danger" wire:click="revoke">Revoke key</flux:button>
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
                <flux:button variant="primary" wire:click="replace">Replace key</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
