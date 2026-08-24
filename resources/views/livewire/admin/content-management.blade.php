<div class="py-8">
    <flux:heading size="xl" class="mb-6">Admin Content Management</flux:heading>

    @if ($successMessage)
        <flux:callout variant="success" icon="check-circle" heading="{{ $successMessage }}" class="mb-6" />
    @endif

    <div class="grid grid-cols-1 gap-6">
        {{-- Terms of Service --}}
        <flux:card class="space-y-4 p-5">
            <div>
                <flux:heading size="lg">Terms of Service</flux:heading>
                <flux:text size="sm" variant="subtle">Shown publicly at {{ url('/terms') }}.</flux:text>
            </div>
            <flux:editor class="dark" wire:model="termsContent" label="Terms of Service content" />
            <div class="flex">
                <flux:spacer />
                <flux:button variant="primary" wire:click="confirmSaveTerms">Save Terms of Service</flux:button>
            </div>
        </flux:card>

        {{-- Privacy Policy --}}
        <flux:card class="space-y-4 p-5">
            <div>
                <flux:heading size="lg">Privacy Policy</flux:heading>
                <flux:text size="sm" variant="subtle">Shown publicly at {{ url('/privacy') }}.</flux:text>
            </div>
            <flux:editor class="dark" wire:model="privacyContent" label="Privacy Policy content" />
            <div class="flex">
                <flux:spacer />
                <flux:button variant="primary" wire:click="confirmSavePrivacy">Save Privacy Policy</flux:button>
            </div>
        </flux:card>

        {{-- FAQs --}}
        <flux:card class="space-y-4 p-5">
            <div>
                <flux:heading size="lg">FAQs</flux:heading>
                <flux:text size="sm" variant="subtle">Content shown on the public support page.</flux:text>
            </div>
            <flux:editor class="dark" wire:model="faqsContent" label="FAQs content" />
            <div class="flex">
                <flux:spacer />
                <flux:button variant="primary" wire:click="confirmSaveFaqs">Save FAQs</flux:button>
            </div>
        </flux:card>
    </div>

    {{-- Confirm Terms modal --}}
    <flux:modal wire:model.self="showConfirmTerms" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Save Terms of Service?</flux:heading>
                <flux:text class="mt-2">This will replace the current Terms of Service content shown publicly at {{ url('/terms') }}.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveTerms">Save</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Confirm Privacy modal --}}
    <flux:modal wire:model.self="showConfirmPrivacy" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Save Privacy Policy?</flux:heading>
                <flux:text class="mt-2">This will replace the current Privacy Policy content shown publicly at {{ url('/privacy') }}.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="savePrivacy">Save</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Confirm FAQs modal --}}
    <flux:modal wire:model.self="showConfirmFaqs" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Save FAQs?</flux:heading>
                <flux:text class="mt-2">This will replace the current FAQs content shown on the public support page.</flux:text>
            </div>
            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveFaqs">Save</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
