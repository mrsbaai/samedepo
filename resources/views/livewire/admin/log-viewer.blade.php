<div class="space-y-4">
    <div class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl">Logs</flux:heading>
            <flux:subheading>View application log files.</flux:subheading>
        </div>
        <flux:text>{{ count($this->files) }} file{{ count($this->files) === 1 ? '' : 's' }}</flux:text>
    </div>

    @if (empty($this->files))
        <flux:callout variant="info" icon="information-circle">
            <flux:callout.text>No log files found.</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
            <div class="space-y-1 lg:col-span-1">
                @foreach ($this->files as $file)
                    <div
                        wire:click="selectFile('{{ $file }}')"
                        @class([
                            'flex cursor-pointer items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm transition',
                            'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-900' => $selectedFile !== $file,
                            'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100' => $selectedFile === $file,
                        ])
                    >
                        <span class="truncate">{{ $file }}</span>
                        <flux:button
                            icon="trash"
                            variant="ghost"
                            size="xs"
                            square
                            color="red"
                            wire:click.stop="deleteFile('{{ $file }}')"
                            aria-label="Delete {{ $file }}"
                        />
                    </div>
                @endforeach
            </div>

            <flux:card class="lg:col-span-4">
                @if ($selectedFile === null)
                    <div class="flex min-h-80 flex-col items-center justify-center text-center">
                        <flux:icon name="document-text" class="h-8 w-8" />
                        <flux:text class="mt-2">Select a log file to view its entries.</flux:text>
                    </div>
                @elseif (empty($this->entries))
                    <div class="flex min-h-80 flex-col items-center justify-center text-center">
                        <flux:icon name="document-text" class="h-8 w-8" />
                        <flux:text class="mt-2">This log file is empty.</flux:text>
                    </div>
                @else
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading size="sm">{{ $selectedFile }}</flux:heading>
                        <flux:text>{{ count($this->entries) }} entr{{ count($this->entries) === 1 ? 'y' : 'ies' }}</flux:text>
                    </div>

                    <div class="overflow-auto max-h-96 space-y-3">
                        @foreach ($this->entries as $index => $entry)
                            @php
                                $levelColor = match (strtolower($entry['level'])) {
                                    'error', 'critical', 'alert', 'emergency' => 'red',
                                    'warning' => 'amber',
                                    'notice' => 'blue',
                                    'info' => 'zinc',
                                    'debug' => 'neutral',
                                    default => 'zinc',
                                };
                            @endphp
                            <div class="flex items-start gap-3 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                                <div class="w-32 shrink-0 whitespace-nowrap font-mono text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                                    {{ $entry['timestamp'] }}
                                </div>
                                <div class="w-28 shrink-0">
                                    <flux:badge size="sm" :color="$levelColor" class="w-full justify-center">{{ $entry['level'] }}</flux:badge>
                                </div>
                                <div class="min-w-0 flex-1">
                                    @if ($entry['exception'])
                                        <div class="break-all font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $entry['exception'] }}
                                        </div>
                                    @endif
                                    <flux:text class="break-all">{{ $entry['summary'] }}</flux:text>
                                </div>
                                <div class="w-8 shrink-0 flex justify-end">
                                    <flux:button
                                        icon="clipboard"
                                        variant="ghost"
                                        size="xs"
                                        square
                                        wire:click="copyEntry({{ $index }})"
                                        aria-label="Copy entry"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        </div>
    @endif
</div>
