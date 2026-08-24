<flux:accordion transition>
    @forelse ($faqs as $faq)
        <flux:accordion.item>
            <flux:accordion.heading>{{ $faq->question }}</flux:accordion.heading>
            <flux:accordion.content>
                {{ $faq->answer }}

                @if ($faq->image_path)
                    <div class="mt-3 flex justify-center">
                        <div class="group relative inline-block">
                            <img src="{{ $faq->imageUrl() }}" alt="FAQ image" class="max-h-64 rounded-xl border border-zinc-200 dark:border-zinc-700">

                            <flux:modal.trigger name="faq-image-{{ $faq->id }}">
                                <button
                                    type="button"
                                    aria-label="View full image"
                                    class="absolute inset-0 flex items-center justify-center rounded-xl bg-black/0 opacity-0 transition-all group-hover:bg-black/40 group-hover:opacity-100"
                                >
                                    <flux:icon icon="arrows-pointing-out" variant="solid" class="size-7 text-white" />
                                </button>
                            </flux:modal.trigger>
                        </div>
                    </div>

                    <flux:modal :name="'faq-image-'.$faq->id" variant="bare" :closable="false" class="max-w-[95vw] p-0">
                        <img src="{{ $faq->imageUrl() }}" alt="FAQ image" class="block max-h-[90vh] max-w-full rounded-lg">

                        <div class="absolute top-0 end-0 mt-4 me-4">
                            <flux:modal.close>
                                <flux:button variant="subtle" icon="x-mark" size="sm" aria-label="Close" />
                            </flux:modal.close>
                        </div>
                    </flux:modal>
                @endif
            </flux:accordion.content>
        </flux:accordion.item>
    @empty
        <flux:text class="text-zinc-500 dark:text-zinc-400">No FAQs have been added yet.</flux:text>
    @endforelse
</flux:accordion>
