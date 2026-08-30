@if ($tickets->isEmpty())
    <flux:callout icon="check-circle" variant="success">
        <flux:callout.heading>All caught up</flux:callout.heading>
        <flux:callout.text>There are no open tickets right now.</flux:callout.text>
    </flux:callout>
@else
    <div class="flex flex-col gap-3">
        @foreach ($tickets as $ticket)
            @php
                $lastMessage = $ticket->latestMessage;
                $needsReply = $lastMessage && ! $lastMessage->user->is_admin;
                $preview = $lastMessage ? \Illuminate\Support\Str::limit(trim(strip_tags($lastMessage->body)), 90) : null;

                $statusColor = match (true) {
                    $needsReply => 'red',
                    $lastMessage && $lastMessage->user->is_admin && $lastMessage->isRead() => 'green',
                    $lastMessage && $lastMessage->user->is_admin => 'amber',
                    default => 'zinc',
                };

                $cardClass = match ($statusColor) {
                    'red' => 'border-l-2 border-l-red-500 dark:border-l-red-400',
                    'amber' => 'border-l-2 border-l-amber-500 dark:border-l-amber-400',
                    'green' => 'border-l-2 border-l-green-500 dark:border-l-green-400',
                    default => '',
                };

                $timeLabel = match (true) {
                    $needsReply => 'User replied',
                    $lastMessage && $lastMessage->user->is_admin && $lastMessage->isRead() => 'Seen',
                    $lastMessage && $lastMessage->user->is_admin => 'Support replied',
                    default => 'Ticket created',
                };
            @endphp
            <a
                href="{{ route('admin.tickets.show', $ticket) }}"
                wire:navigate
                wire:key="open-ticket-{{ $ticket->id }}"
                class="block rounded-xl"
            >
                <flux:card class="group flex items-center gap-4 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-700/50 {{ $cardClass }}">
                    <flux:avatar :src="$ticket->user->avatarUrl()" :name="$ticket->user->email" size="md" class="shrink-0" />

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <flux:heading class="truncate">{{ $ticket->subject }}</flux:heading>

                            @if ($needsReply)
                                <flux:badge size="sm" color="red">Needs reply</flux:badge>
                            @elseif ($lastMessage && $lastMessage->user->is_admin)
                                @if ($lastMessage->isRead())
                                    <flux:badge size="sm" color="green">Seen</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber">Unseen</flux:badge>
                                @endif
                            @endif
                        </div>

                        <flux:text size="sm" class="mt-1 truncate text-zinc-500 dark:text-zinc-400">
                            {{ $ticket->user->email }}
                            @if ($preview)
                                · {{ $preview }}
                            @endif
                        </flux:text>
                    </div>

                    <div class="flex shrink-0 items-center gap-4">
                        <flux:text size="sm" class="whitespace-nowrap text-zinc-400">
                            {{ $timeLabel }} · {{ $ticket->last_message_at?->diffForHumans() }}
                        </flux:text>

                        @if (! $needsReply)
                            <flux:button
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="x-mark"
                                aria-label="Close ticket"
                                wire:click.stop.prevent="closeTicket({{ $ticket->id }})"
                            />
                        @endif
                    </div>
                </flux:card>
            </a>
        @endforeach
    </div>
@endif
