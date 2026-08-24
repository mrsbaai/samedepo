<x-dashboard.layout title="User Summary">
    <div class="flex flex-col gap-6">
        <div class="flex items-center gap-4">
            <flux:avatar :src="$user->avatarUrl()" :name="$user->email" size="xl" />
            <div>
                <flux:heading size="xl">User Summary</flux:heading>
                <flux:subheading class="mt-2">{{ $user->email }}</flux:subheading>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <flux:card class="h-full">
                    <flux:heading size="lg" class="mb-4">Overview</flux:heading>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <flux:subheading size="sm">Email</flux:subheading>
                            <flux:heading class="mt-1 break-all">{{ $user->email }}</flux:heading>
                        </div>

                        <div>
                            <flux:subheading size="sm">Status</flux:subheading>
                            <div class="mt-1">
                                <flux:badge size="sm" :color="$user->is_active ? 'green' : 'red'">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </flux:badge>
                            </div>
                        </div>

                        <div>
                            <flux:subheading size="sm">Signed up</flux:subheading>
                            <flux:heading class="mt-1">{{ $user->created_at->diffForHumans() }}</flux:heading>
                            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">
                                {{ $user->created_at->toDateTimeString() }}
                            </flux:text>
                        </div>
                    </div>
                </flux:card>
            </div>

            <div class="space-y-6">
                <flux:card class="h-full">
                    <flux:heading size="lg" class="mb-4">Actions</flux:heading>
                    <flux:subheading>No actions available yet.</flux:subheading>
                </flux:card>
            </div>
        </div>
    </div>
</x-dashboard.layout>
