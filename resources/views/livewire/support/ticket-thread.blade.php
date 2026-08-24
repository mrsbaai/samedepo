<div class="mx-auto max-w-2xl">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            @unless ($ticket->isOpen())
                <flux:badge color="zinc" inset="top bottom">{{ ucfirst($ticket->status) }}</flux:badge>
            @endunless
            <flux:heading size="xl" level="1">{{ $ticket->subject }}</flux:heading>
        </div>

        @if ($ticket->isOpen())
            <flux:button variant="filled" size="sm" icon="lock-closed" wire:click="toggleStatus">Close ticket</flux:button>
        @elseif (auth()->user()->is_admin)
            <flux:button variant="filled" size="sm" icon="lock-open" wire:click="toggleStatus">Reopen ticket</flux:button>
        @endif
    </div>

    <flux:separator class="mt-4 mb-6" />

    <div class="flex flex-col gap-5">
        @foreach ($messages as $message)
            @php
                $isMine = $message->user_id === auth()->id();
                $isFromAdmin = (bool) $message->user->is_admin;
                $canModify = auth()->user()->is_admin && $isMine && ! $message->isRead();
                $avatarUrl = 'https://unavatar.io/'.urlencode($message->user->email).'?fallback=https://ui-avatars.com/api/?name='.urlencode($message->user->email);
            @endphp

            <div class="flex items-start gap-3 {{ $isMine ? 'flex-row-reverse' : '' }}" wire:key="message-{{ $message->id }}">
                @if ($isFromAdmin && $message->authorAvatarUrl())
                    <flux:avatar :src="$message->authorAvatarUrl()" size="xs" class="mt-1 shrink-0" />
                @elseif ($isFromAdmin)
                    <flux:avatar :name="$message->authorDisplayName()" size="xs" class="mt-1 shrink-0" />
                @else
                    <flux:avatar :src="$avatarUrl" :name="$message->authorDisplayName()" size="xs" class="mt-1 shrink-0" />
                @endif

                <div class="flex max-w-[80%] flex-col {{ $isMine ? 'items-end' : 'items-start' }}">
                    <div class="flex items-center gap-1.5">
                        <flux:text size="sm" class="font-medium text-zinc-700 dark:text-zinc-200">{{ $message->authorDisplayName() }}</flux:text>

                        @if ($canModify && $editingMessageId !== $message->id)
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" class="!size-6 !p-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" aria-label="Message options" />
                                <flux:menu class="dark">
                                    <flux:menu.item icon="pencil-square" wire:click="startEditing({{ $message->id }})">Edit</flux:menu.item>
                                    <flux:menu.item variant="danger" icon="trash" wire:click="deleteMessage({{ $message->id }})" wire:confirm="Delete this message?">Delete</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        @endif
                    </div>

                    @if ($editingMessageId === $message->id)
                        <div class="mt-1 w-full">
                            <flux:editor class="dark" wire:model="editBody" placeholder="Edit your message…" />
                            @error('editBody') <flux:text size="sm" class="mt-1 text-red-500">{{ $errors->first('editBody') }}</flux:text> @enderror
                            <div class="mt-2 flex items-center gap-2 justify-end">
                                <flux:button size="sm" variant="ghost" wire:click="cancelEditing">Cancel</flux:button>
                                <flux:button size="sm" variant="primary" wire:click="saveEdit">Save</flux:button>
                            </div>
                        </div>
                    @else
                        <div class="mt-1 rounded-2xl px-4 py-2.5 prose prose-sm prose-invert max-w-none bg-zinc-800 text-white">
                            {!! $message->body !!}
                        </div>
                    @endif

                    @if ($message->image_path)
                        <div class="group relative mt-2 inline-block">
                            <img src="{{ $message->imageUrl() }}" alt="Attachment" class="max-h-64 rounded-xl border border-zinc-200 dark:border-zinc-700">

                            <flux:modal.trigger name="attachment-{{ $message->id }}">
                                <button
                                    type="button"
                                    aria-label="View full image"
                                    class="absolute inset-0 flex items-center justify-center rounded-xl bg-black/0 opacity-0 transition-all group-hover:bg-black/40 group-hover:opacity-100"
                                >
                                    <flux:icon icon="arrows-pointing-out" variant="solid" class="size-7 text-white" />
                                </button>
                            </flux:modal.trigger>
                        </div>

                        <flux:modal :name="'attachment-'.$message->id" variant="bare" :closable="false" class="max-w-[95vw] p-0">
                            <img src="{{ $message->imageUrl() }}" alt="Attachment" class="block max-h-[90vh] max-w-full rounded-lg">

                            <div class="absolute top-0 end-0 mt-4 me-4">
                                <flux:modal.close>
                                    <flux:button variant="outline" icon="x-mark" size="sm" aria-label="Close" />
                                </flux:modal.close>
                            </div>
                        </flux:modal>
                    @endif

                    <flux:text size="sm" class="mt-1 text-zinc-400">
                        {{ $message->created_at->diffForHumans() }}
                        @if ($isMine && auth()->user()->is_admin)
                            · {{ $message->isRead() ? 'Seen' : 'Sent' }}
                        @endif
                    </flux:text>
                </div>
            </div>
        @endforeach
    </div>

    @if ($ticket->isOpen())
        <div class="mt-6 space-y-3">
            @if (auth()->user()->is_admin)
                @if ($image)
                    @include('livewire.support.partials.image-attachment-preview')
                @endif

                <flux:select wire:model.live="identityRole" label="Reply as" size="sm" variant="listbox">
                    @foreach ($identities as $identity)
                        <flux:select.option value="{{ $identity->role }}">{{ $identity->displayName() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:editor class="dark" wire:model="body" label="Reply" label:sr-only placeholder="Write a reply…" />

                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <flux:file-upload wire:model="image" accept="image/*">
                            <flux:tooltip content="Attach an image" class="contents">
                                <flux:button type="button" size="sm" variant="subtle" icon="paper-clip" />
                            </flux:tooltip>
                        </flux:file-upload>

                        <flux:button
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="sparkles"
                            wire:click="suggestReply"
                            wire:loading.attr="disabled"
                            wire:target="suggestReply"
                        >
                            <span wire:loading.remove wire:target="suggestReply">Suggest reply</span>
                            <span wire:loading wire:target="suggestReply">Thinking…</span>
                        </flux:button>
                    </div>

                    <flux:button variant="primary" size="sm" icon="paper-airplane" wire:click="reply">Send reply</flux:button>
                </div>
            @else
                <form wire:submit="reply">
                    <flux:composer class="dark" wire:model="body" label="Reply" label:sr-only placeholder="Write a reply…">
                        @if ($image)
                            <x-slot name="header">
                                @include('livewire.support.partials.image-attachment-preview')
                            </x-slot>
                        @endif

                        <x-slot name="actionsLeading">
                            <flux:file-upload wire:model="image" accept="image/*">
                                <flux:tooltip content="Attach an image" class="contents">
                                    <flux:button type="button" size="sm" variant="subtle" icon="paper-clip" />
                                </flux:tooltip>
                            </flux:file-upload>
                        </x-slot>

                        <x-slot name="actionsTrailing">
                            <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">Send</flux:button>
                        </x-slot>
                    </flux:composer>
                </form>
            @endif
        </div>
    @else
        <flux:callout class="mt-6" icon="lock-closed">
            <flux:callout.text>
                @if (auth()->user()->is_admin)
                    This ticket is closed. Reopen it to send another reply.
                @else
                    This ticket is closed. <flux:link href="{{ route('support.tickets.create') }}" wire:navigate>Open a new ticket</flux:link> if you still need help.
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif
</div>
