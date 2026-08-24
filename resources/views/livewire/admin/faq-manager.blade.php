<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">FAQs</flux:heading>
        <flux:subheading class="mt-2">Manage the questions and answers shown on the support page.</flux:subheading>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="space-y-4">
        <flux:heading size="lg">Add a FAQ</flux:heading>

        <flux:input wire:model="question" label="Question" placeholder="What is…" />
        <flux:textarea wire:model="answer" label="Answer" rows="4" />

        @if ($image)
            @include('livewire.support.partials.image-attachment-preview')
        @endif

        <div class="flex items-center justify-between">
            <flux:file-upload wire:model="image" accept="image/*">
                <flux:tooltip content="Attach an image (optional)" class="contents">
                    <flux:button type="button" size="sm" variant="subtle" icon="paper-clip" />
                </flux:tooltip>
            </flux:file-upload>

            <flux:button variant="primary" icon="plus" wire:click="create">Add FAQ</flux:button>
        </div>
    </flux:card>

    <div class="space-y-3">
        @forelse ($faqs as $index => $faq)
            <flux:card class="space-y-3" wire:key="faq-{{ $faq->id }}">
                @if ($editingId === $faq->id)
                    <flux:input wire:model="editQuestion" label="Question" />
                    <flux:textarea wire:model="editAnswer" label="Answer" rows="4" />

                    @if ($editImage)
                        @include('livewire.support.partials.image-attachment-preview', ['image' => $editImage, 'property' => 'editImage'])
                    @elseif ($faq->image_path && ! $removeEditImage)
                        <div class="relative inline-block overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <img src="{{ $faq->imageUrl() }}" alt="FAQ image" class="size-14 object-cover">

                            <div class="absolute top-0 right-0 p-1">
                                <button type="button" wire:click="$set('removeEditImage', true)" class="flex items-center justify-center rounded-full bg-zinc-900/50 p-0.5 hover:bg-zinc-900/70">
                                    <flux:icon icon="x-mark" variant="micro" class="text-white" />
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center justify-between">
                        <flux:file-upload wire:model="editImage" accept="image/*">
                            <flux:tooltip content="Attach an image (optional)" class="contents">
                                <flux:button type="button" size="sm" variant="subtle" icon="paper-clip" />
                            </flux:tooltip>
                        </flux:file-upload>

                        <div class="flex gap-2">
                            <flux:button variant="primary" size="sm" wire:click="saveEdit">Save</flux:button>
                            <flux:button variant="ghost" size="sm" wire:click="cancelEdit">Cancel</flux:button>
                        </div>
                    </div>
                @else
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <flux:heading>{{ $faq->question }}</flux:heading>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $faq->answer }}</flux:text>
                            @if ($faq->image_path)
                                <flux:badge size="sm" icon="photo">Has image</flux:badge>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button variant="ghost" size="sm" icon="chevron-up" :disabled="$index === 0" wire:click="moveUp({{ $faq->id }})" aria-label="Move up" />
                            <flux:button variant="ghost" size="sm" icon="chevron-down" :disabled="$index === $faqs->count() - 1" wire:click="moveDown({{ $faq->id }})" aria-label="Move down" />
                            <flux:button variant="ghost" size="sm" icon="pencil" wire:click="startEdit({{ $faq->id }})" aria-label="Edit" />
                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete({{ $faq->id }})" wire:confirm="Delete this FAQ?" aria-label="Delete" />
                        </div>
                    </div>
                @endif
            </flux:card>
        @empty
            <flux:text class="text-zinc-500 dark:text-zinc-400">No FAQs yet. Add the first one above.</flux:text>
        @endforelse
    </div>
</div>
