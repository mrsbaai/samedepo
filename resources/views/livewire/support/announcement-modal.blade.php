<div>
    @if ($show)
        <flux:modal wire:model.self="show" class="md:w-96">
            <div class="space-y-4">
                <div class="flex items-center gap-2">
                    <flux:icon icon="megaphone" class="text-zinc-400" />
                    <flux:heading size="lg">Announcement</flux:heading>
                </div>

                <div class="prose prose-sm dark:prose-invert max-w-none">
                    {!! $announcement->content !!}
                </div>

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="primary">Got it</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
