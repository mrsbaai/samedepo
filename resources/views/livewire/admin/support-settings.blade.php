<div class="mx-auto max-w-3xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">Settings</flux:heading>
        <flux:subheading class="mt-2">Manage support identities and AI instructions.</flux:subheading>
    </div>

    <flux:card>
        <flux:heading size="lg" class="mb-4">Service context</flux:heading>
        <flux:subheading class="mb-4">
            Helps the AI understand what your product is, who it is for, and how to refer to it.
        </flux:subheading>

        <flux:textarea wire:model="serviceDescription" label="What is this service?" rows="3" placeholder="A simple invoicing tool for freelancers..." />
        <flux:textarea wire:model="serviceUseCase" class="mt-4" label="What is it used for?" rows="3" placeholder="Users create and send invoices, track payments, and download tax reports..." />

        <flux:subheading size="sm" class="mt-4 text-zinc-500 dark:text-zinc-400">
            Service name and domain are pulled from <code>APP_NAME</code> and <code>APP_URL</code>.
        </flux:subheading>

        <div class="mt-4 flex justify-end">
            <flux:button variant="primary" size="sm" wire:click="saveServiceContext">Save context</flux:button>
        </div>
    </flux:card>

    <flux:card>
        <flux:heading size="lg" class="mb-4">AI special instructions</flux:heading>
        <flux:subheading class="mb-4">
            Context the AI should know when suggesting replies (e.g. “Feature X is currently disabled” or “All refunds require manager approval”).
        </flux:subheading>

        <flux:textarea wire:model="specialInstructions" rows="6" placeholder="Enter special instructions..." />

        <div class="mt-4 flex justify-end">
            <flux:button variant="primary" size="sm" wire:click="saveSpecialInstructions">Save instructions</flux:button>
        </div>
    </flux:card>

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($identities as $identity)
            <flux:card class="flex items-center gap-4">
                @if ($identity->avatarUrl())
                    <flux:avatar :src="$identity->avatarUrl()" size="lg" class="shrink-0" />
                @else
                    <flux:avatar :name="$identity->label()" size="lg" class="shrink-0" />
                @endif

                <div class="min-w-0 flex-1">
                    <flux:heading size="sm" class="truncate">{{ $identity->label() }}</flux:heading>
                    <flux:text size="sm" class="truncate text-zinc-500 dark:text-zinc-400">
                        {{ $identity->name ?? 'No name set' }}
                    </flux:text>
                </div>

                <flux:button size="sm" variant="subtle" wire:click="openIdentityModal('{{ $identity->role }}')">Edit</flux:button>
            </flux:card>
        @endforeach
    </div>

    <flux:modal wire:model="isModalOpen" name="edit-identity" variant="dialog">
        @if ($editingRole)
            <flux:heading size="lg">{{ ucfirst($editingRole) }} identity</flux:heading>
            <flux:subheading size="sm" class="mt-1">Set the name and avatar used when replying as this identity.</flux:subheading>

            <div class="mt-6 space-y-6">
                <flux:input wire:model="editingName" label="Name" placeholder="Alex" />

                <div class="space-y-6">
                    <div>
                        <flux:label>Or upload your own</flux:label>
                        <div class="mt-3">
                            <flux:file-upload wire:model="uploadedAvatar" accept="image/*">
                                <flux:button type="button" size="sm" variant="outline" icon="arrow-up-tray">Upload image</flux:button>
                            </flux:file-upload>

                            @if ($uploadedAvatar)
                                <div class="mt-3">
                                    <img src="{{ $uploadedAvatar->temporaryUrl() }}" alt="Upload preview" class="size-14 rounded-lg object-cover">
                                </div>
                            @elseif ($editingAvatar && ! str_starts_with($editingAvatar, 'support-agents/'))
                                <div class="mt-3">
                                    <img src="{{ Storage::disk('public')->url($editingAvatar) }}" alt="Current avatar" class="size-14 rounded-lg object-cover">
                                </div>
                            @endif
                        </div>
                    </div>

                    <div>
                        <flux:label>Choose from defaults</flux:label>

                        <div class="mt-3 flex flex-wrap gap-3">
                            @foreach ($avatars as $avatar)
                                <button
                                    type="button"
                                    wire:click="selectAvatar('{{ $avatar['path'] }}')"
                                    class="relative size-14 shrink-0 rounded-xl border-2 p-1 transition-all {{ $editingAvatar === $avatar['path'] && ! $uploadedAvatar ? 'border-(--color-accent)' : 'border-transparent hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                                >
                                    <img src="{{ $avatar['url'] }}" alt="Avatar option" class="aspect-square w-full rounded-lg object-cover">
                                    @if ($editingAvatar === $avatar['path'] && ! $uploadedAvatar)
                                        <div class="absolute -right-1 -top-1 flex size-5 items-center justify-center rounded-full bg-(--color-accent) text-(--color-accent-foreground)">
                                            <flux:icon icon="check" variant="micro" />
                                        </div>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-8 flex justify-end gap-2">
                <flux:button size="sm" variant="ghost" wire:click="closeIdentityModal">Cancel</flux:button>
                <flux:button size="sm" variant="primary" wire:click="saveIdentity">Save</flux:button>
            </div>
        @endif
    </flux:modal>
</div>
