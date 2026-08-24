<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">User Search</flux:heading>
        <flux:subheading class="mt-2">Search users by email address.</flux:subheading>
    </div>

    <flux:input
        wire:model.live.debounce.300ms="query"
        icon="magnifying-glass"
        placeholder="Search users..."
        aria-label="Search users"
    />

    @if ($users->isEmpty())
        <flux:callout icon="magnifying-glass">
            <flux:callout.heading>No users found</flux:callout.heading>
        </flux:callout>
    @else
        <div class="grid gap-3">
            @foreach ($users as $user)
                <a
                    href="{{ route('admin.users.summary', $user) }}"
                    wire:navigate
                    wire:key="user-{{ $user->id }}"
                    class="block rounded-xl"
                >
                    <flux:card class="group flex items-center gap-4 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <flux:avatar :src="$user->avatarUrl()" :name="$user->email" size="md" class="shrink-0" />

                        <div class="min-w-0 flex-1">
                            <flux:heading class="truncate">{{ $user->email }}</flux:heading>

                            <flux:text size="sm" class="mt-1 truncate text-zinc-500 dark:text-zinc-400">
                                Signed up {{ $user->created_at->diffForHumans() }}
                            </flux:text>
                        </div>

                        <flux:badge size="sm" :color="$user->is_active ? 'green' : 'red'">
                            {{ $user->is_active ? 'Active' : 'Inactive' }}
                        </flux:badge>
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif
</div>
