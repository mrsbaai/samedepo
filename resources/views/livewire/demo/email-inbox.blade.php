<div class="flex h-screen flex-col overflow-hidden">
    {{-- Top bar --}}
    <flux:header class="dark shrink-0 border-b border-zinc-200 dark:border-zinc-800">
        <div class="flex items-center gap-3">
            <flux:icon name="envelope" class="size-6 text-accent" />
            <flux:heading size="lg">Mailbox</flux:heading>
            <flux:badge size="sm" color="zinc">Demo</flux:badge>
        </div>

        <flux:spacer />

        <div class="w-full max-w-md max-md:hidden">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search mail..." clearable size="sm" />
        </div>

        <flux:spacer />

        <flux:navbar class="mr-1">
            <flux:navbar.item icon="cog-6-tooth" href="#" label="Settings" />
            <flux:navbar.item icon="bell" href="#" label="Notifications" />
        </flux:navbar>

        <flux:dropdown position="bottom" align="end">
            <flux:profile avatar:name="Alex Rivera" chevron />
            <flux:navmenu class="dark">
                <flux:navmenu.item icon="user" href="#">Profile</flux:navmenu.item>
                <flux:navmenu.item icon="arrow-right-start-on-rectangle" href="#">Sign out</flux:navmenu.item>
            </flux:navmenu>
        </flux:dropdown>
    </flux:header>

    <div class="flex min-h-0 flex-1">
        {{-- Folder rail --}}
        <div class="flex w-56 shrink-0 flex-col gap-4 border-r border-zinc-200 bg-zinc-50 p-4 max-lg:hidden dark:border-zinc-800 dark:bg-zinc-900">
            <flux:modal.trigger name="compose">
                <flux:button variant="primary" icon="pencil-square" class="w-full">Compose</flux:button>
            </flux:modal.trigger>

            <flux:navlist>
                <flux:navlist.item icon="inbox" wire:click="setFolder('inbox')" :current="$folder === 'inbox'" :badge="$this->unreadCount() ?: null" as="button" class="w-full">
                    Inbox
                </flux:navlist.item>
                <flux:navlist.item icon="star" wire:click="setFolder('starred')" :current="$folder === 'starred'" as="button" class="w-full">
                    Starred
                </flux:navlist.item>
                <flux:navlist.item icon="paper-airplane" wire:click="setFolder('sent')" :current="$folder === 'sent'" as="button" class="w-full">
                    Sent
                </flux:navlist.item>
                <flux:navlist.item icon="document" wire:click="setFolder('drafts')" :current="$folder === 'drafts'" :badge="$this->counts('drafts') ?: null" as="button" class="w-full">
                    Drafts
                </flux:navlist.item>
                <flux:navlist.item icon="archive-box" wire:click="setFolder('archive')" :current="$folder === 'archive'" as="button" class="w-full">
                    Archive
                </flux:navlist.item>
                <flux:navlist.item icon="trash" wire:click="setFolder('trash')" :current="$folder === 'trash'" as="button" class="w-full">
                    Trash
                </flux:navlist.item>
            </flux:navlist>

            <flux:separator variant="subtle" />

            <flux:navlist>
                <flux:navlist.group heading="Labels">
                    <flux:navlist.item as="button" class="w-full">
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-blue-500"></span> Work
                        </div>
                    </flux:navlist.item>
                    <flux:navlist.item as="button" class="w-full">
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-green-500"></span> Sales
                        </div>
                    </flux:navlist.item>
                    <flux:navlist.item as="button" class="w-full">
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-purple-500"></span> Dev
                        </div>
                    </flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <flux:text size="sm" variant="subtle">2.4 GB of 15 GB used</flux:text>
        </div>

        {{-- Message list --}}
        <div class="flex w-full max-w-sm shrink-0 flex-col border-r border-zinc-200 max-md:max-w-full dark:border-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <flux:heading>{{ ucfirst($folder) }}</flux:heading>
                <flux:text size="sm" variant="subtle">{{ $this->filtered->count() }} {{ str('message')->plural($this->filtered->count()) }}</flux:text>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                @forelse ($this->filtered as $message)
                    <button
                        wire:click="select({{ $message['id'] }})"
                        wire:key="message-{{ $message['id'] }}"
                        @class([
                            'block w-full border-b border-zinc-100 px-4 py-3 text-left transition hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/50',
                            'bg-zinc-100 dark:bg-zinc-800' => $selectedId === $message['id'],
                        ])
                    >
                        <div class="flex items-center gap-2">
                            <flux:avatar size="xs" :name="$message['from']" :color="'auto'" />
                            <span @class(['flex-1 truncate text-sm', 'font-bold' => $message['unread'], 'font-medium' => ! $message['unread']])>
                                {{ $message['from'] }}
                            </span>
                            @if ($message['unread'])
                                <span class="size-2 shrink-0 rounded-full bg-accent"></span>
                            @endif
                            <flux:text size="xs" variant="subtle">{{ $message['time'] }}</flux:text>
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <span @class(['truncate text-sm', 'font-semibold' => $message['unread']])>{{ $message['subject'] }}</span>
                        </div>
                        <flux:text size="sm" variant="subtle" class="mt-0.5 line-clamp-1">{{ $message['preview'] }}</flux:text>
                        @if ($message['label'])
                            <flux:badge size="sm" class="mt-1.5" :color="['Work' => 'blue', 'Sales' => 'green', 'Dev' => 'purple', 'News' => 'amber'][$message['label']] ?? 'zinc'">
                                {{ $message['label'] }}
                            </flux:badge>
                        @endif
                    </button>
                @empty
                    <div class="py-16 text-center">
                        <flux:icon name="inbox" class="mx-auto size-10 text-zinc-300 dark:text-zinc-600" />
                        <flux:heading size="sm" class="mt-3">Nothing here</flux:heading>
                        <flux:text size="sm" variant="subtle" class="mt-1">
                            {{ $search ? 'No messages match your search.' : 'This folder is empty.' }}
                        </flux:text>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Reading pane --}}
        <div class="flex min-w-0 flex-1 flex-col max-md:hidden">
            @if ($this->selected)
                <div class="flex items-center gap-1 border-b border-zinc-200 px-4 py-2 dark:border-zinc-800">
                    <flux:button icon="archive-box" variant="ghost" size="sm" tooltip="Archive" wire:click="moveTo({{ $this->selected['id'] }}, 'archive')" />
                    <flux:button icon="trash" variant="ghost" size="sm" tooltip="Delete" wire:click="moveTo({{ $this->selected['id'] }}, 'trash')" />
                    <flux:button
                        :icon="$this->selected['starred'] ? 'star' : 'star'"
                        :icon:variant="$this->selected['starred'] ? 'solid' : 'outline'"
                        variant="ghost"
                        size="sm"
                        tooltip="{{ $this->selected['starred'] ? 'Unstar' : 'Star' }}"
                        wire:click="toggleStar({{ $this->selected['id'] }})"
                        class="{{ $this->selected['starred'] ? 'text-amber-500!' : '' }}"
                    />

                    <flux:separator vertical class="mx-1 my-1.5" />

                    <flux:button icon="arrow-uturn-left" variant="ghost" size="sm" tooltip="Reply" wire:click="openReply" />
                    <flux:button icon="arrow-uturn-right" variant="ghost" size="sm" tooltip="Forward" />

                    <flux:spacer />

                    <flux:dropdown position="bottom" align="end">
                        <flux:button icon="ellipsis-horizontal" variant="ghost" size="sm" />
                        <flux:menu class="dark">
                            <flux:menu.item icon="envelope">Mark as unread</flux:menu.item>
                            <flux:menu.item icon="clock">Snooze</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="exclamation-triangle" variant="danger">Report spam</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-6">
                    <div class="flex items-start justify-between gap-4">
                        <flux:heading size="xl">{{ $this->selected['subject'] }}</flux:heading>
                        @if ($this->selected['label'])
                            <flux:badge :color="['Work' => 'blue', 'Sales' => 'green', 'Dev' => 'purple', 'News' => 'amber'][$this->selected['label']] ?? 'zinc'">
                                {{ $this->selected['label'] }}
                            </flux:badge>
                        @endif
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <flux:avatar :name="$this->selected['from']" :color="'auto'" />
                        <div class="min-w-0 flex-1">
                            <flux:heading size="sm">{{ $this->selected['from'] }}</flux:heading>
                            <flux:text size="sm" variant="subtle" class="truncate">{{ $this->selected['email'] }} · to me</flux:text>
                        </div>
                        <flux:text size="sm" variant="subtle">{{ $this->selected['time'] }}</flux:text>
                    </div>

                    <flux:separator class="my-6" variant="subtle" />

                    <flux:text class="whitespace-pre-line leading-relaxed">{{ $this->selected['body'] }}</flux:text>

                    <div class="mt-8 flex gap-2">
                        <flux:button icon="arrow-uturn-left" variant="outline" size="sm" wire:click="openReply">Reply</flux:button>
                        <flux:button icon="arrow-uturn-right" variant="outline" size="sm">Forward</flux:button>
                    </div>
                </div>
            @else
                <div class="flex flex-1 items-center justify-center">
                    <div class="text-center">
                        <flux:icon name="envelope-open" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                        <flux:heading size="sm" class="mt-4">No message selected</flux:heading>
                        <flux:text size="sm" variant="subtle" class="mt-1">Choose a conversation from the list to read it here.</flux:text>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Reply modal --}}
    <flux:modal name="reply" class="w-full max-w-lg">
        @if ($this->selected)
            <form wire:submit="sendReply" class="space-y-4">
                <flux:heading size="lg">Reply</flux:heading>

                <div class="flex items-center gap-3 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                    <flux:avatar size="sm" :name="$this->selected['from']" :color="'auto'" />
                    <div class="min-w-0 flex-1">
                        <flux:text size="sm" class="truncate font-medium">To: {{ $this->selected['from'] }} &lt;{{ $this->selected['email'] }}&gt;</flux:text>
                        <flux:text size="xs" variant="subtle" class="truncate">Re: {{ $this->selected['subject'] }}</flux:text>
                    </div>
                </div>

                <flux:textarea wire:model="replyBody" rows="6" placeholder="Write your reply..." />

                <div class="flex items-center gap-2">
                    <flux:button type="submit" variant="primary" icon="paper-airplane">Send reply</flux:button>
                    <flux:button icon="paper-clip" variant="ghost" tooltip="Attach files" />
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Discard</flux:button>
                    </flux:modal.close>
                </div>
            </form>
        @endif
    </flux:modal>

    {{-- Compose modal --}}
    <flux:modal name="compose" class="w-full max-w-lg">
        <form wire:submit="send" class="space-y-4">
            <flux:heading size="lg">New message</flux:heading>

            <flux:input wire:model="to" label="To" type="email" placeholder="someone@example.com" />
            <flux:input wire:model="subject" label="Subject" placeholder="Subject" />
            <flux:textarea wire:model="body" label="Message" rows="8" placeholder="Write your message..." />

            <div class="flex items-center gap-2">
                <flux:button type="submit" variant="primary" icon="paper-airplane">Send</flux:button>
                <flux:button icon="paper-clip" variant="ghost" tooltip="Attach files" />
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Discard</flux:button>
                </flux:modal.close>
            </div>
        </form>
    </flux:modal>
</div>
