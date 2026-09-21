<div class="py-8">
    @if ($this->uiState === 'error')
        <div class="max-w-2xl mx-auto">
            <flux:callout variant="danger" icon="x-circle" heading="Couldn't load API keys">
                <flux:callout.text>{{ $this->errorMessage }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button wire:click="retry" icon="arrow-path" variant="ghost">Retry</flux:button>
                </x-slot>
            </flux:callout>
        </div>
    @elseif ($this->uiState === 'loading')
        <div class="max-w-2xl mx-auto">
            <flux:skeleton class="h-8 w-48 mb-6" />
            @foreach (range(1, 3) as $i)
                <flux:skeleton class="h-12 w-full mb-3" />
            @endforeach
        </div>
    @else
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading size="xl">API Keys</flux:heading>
                    <flux:subheading class="mt-2">Keys authenticate API requests from your integration.</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">Create key</flux:button>
            </div>

            @if ($successMessage)
                <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" />
            @endif

            @if ($revealedKey)
                <flux:callout variant="warning" icon="exclamation-triangle" heading="Copy your key now">
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
                <flux:card>
                    <div class="space-y-4">
                        @foreach ($this->keys as $key)
                            <div class="flex items-center gap-3" wire:key="key-{{ $key->id }}">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium truncate">{{ $key->name }}</flux:text>
                                        @if ($key->key_prefix)
                                            <flux:badge size="sm" class="font-ledger">{{ $key->key_prefix }}…</flux:badge>
                                        @endif
                                    </div>
                                    <flux:text size="sm" variant="subtle" class="mt-0.5">
                                        Created {{ \App\Support\Dates::humanFlat($key->created_at) }} · Last used {{ $key->last_used_at ? \App\Support\Dates::humanFlat($key->last_used_at) : 'Never' }}
                                    </flux:text>
                                </div>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                    <flux:menu class="dark">
                                        <flux:menu.item icon="arrow-path" wire:click="confirmReplace({{ $key->id }})">Replace key</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmRevoke({{ $key->id }})">Revoke key</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                            @if (! $loop->last)
                                <flux:separator variant="subtle" />
                            @endif
                        @endforeach
                    </div>
                </flux:card>

                @if ($this->keys->hasPages())
                    <flux:pagination :paginator="$this->keys" />
                @endif
            @endif
        </div>
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
