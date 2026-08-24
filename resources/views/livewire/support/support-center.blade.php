<div class="mx-auto max-w-3xl">
    <flux:heading size="xl" level="1">Support</flux:heading>
    <flux:subheading class="mt-2">Answers to common questions. Can't find what you need? Reach out to us.</flux:subheading>

    <flux:tab.group class="mt-8">
        <flux:tabs wire:model="tab">
            <flux:tab name="faqs">FAQs</flux:tab>
            <flux:tab name="tickets">My Tickets</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="faqs">
            @include('livewire.support.partials.faq-accordion')
        </flux:tab.panel>

        <flux:tab.panel name="tickets">
            @php
                $hasOpenTicket = $tickets->contains(fn ($ticket) => $ticket->isOpen());
            @endphp

            @if (! $hasOpenTicket)
                <div class="mt-6 flex items-center justify-between">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">No open tickets</flux:text>
                    <flux:button href="{{ route('support.tickets.create') }}" size="sm" icon="plus" wire:navigate>New ticket</flux:button>
                </div>
            @endif

            @if ($tickets->isNotEmpty())
                <div class="mt-4 flex flex-col gap-3">
                    @foreach ($tickets as $ticket)
                        @php
                            $unread = $ticket->unreadCountFor(auth()->user());
                            $lastMessage = $ticket->latestMessage;
                            $preview = $lastMessage ? \Illuminate\Support\Str::limit(trim(strip_tags($lastMessage->body)), 100) : null;
                            $isClosed = ! $ticket->isOpen();
                            $lastFromAdmin = $lastMessage && $lastMessage->user->is_admin;

                            $isInitialMessage = $lastMessage && $ticket->messages_count === 1 && ! $lastMessage->user->is_admin;

                            $timeLabel = match (true) {
                                $isClosed => 'Ticket closed',
                                $lastFromAdmin => 'Support replied',
                                $isInitialMessage => 'Ticket created',
                                (bool) $lastMessage => 'You replied',
                                default => 'Ticket created',
                            };
                        @endphp
                        <a
                            href="{{ route('support.tickets.show', $ticket) }}"
                            wire:navigate
                            wire:key="ticket-{{ $ticket->id }}"
                            class="block rounded-xl {{ $ticket->isOpen() ? '' : 'opacity-70' }}"
                        >
                            <flux:card class="flex items-center gap-4 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                <flux:avatar icon="lifebuoy" :color="$ticket->statusColorForUser()" class="shrink-0" />

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <flux:heading class="truncate">{{ $ticket->subject }}</flux:heading>
                                        <flux:badge size="sm" :color="$ticket->statusColorForUser()">{{ $ticket->statusLabelForUser() }}</flux:badge>

                                        @if ($unread > 0)
                                            <flux:badge size="sm" color="amber" class="animate-pulse">{{ $unread }} new</flux:badge>
                                        @endif
                                    </div>

                                    @if ($preview)
                                        <flux:text size="sm" class="mt-1 truncate text-zinc-500 dark:text-zinc-400">
                                            {{ $preview }}
                                        </flux:text>
                                    @endif
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <flux:text size="sm" class="whitespace-nowrap text-zinc-400">
                                        {{ $timeLabel }} · {{ $ticket->last_message_at?->diffForHumans() }}
                                    </flux:text>
                                    <flux:icon icon="chevron-right" variant="micro" class="text-zinc-400" />
                                </div>
                            </flux:card>
                        </a>
                    @endforeach
                </div>
            @endif
        </flux:tab.panel>
    </flux:tab.group>
</div>
