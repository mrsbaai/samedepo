<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\FaqsContent;
use App\Models\LegalPage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Content Management'])]
class ContentManagement extends Component
{
    #[Validate('required|string')]
    public string $termsContent = '';

    #[Validate('required|string')]
    public string $privacyContent = '';

    #[Validate('required|string')]
    public string $faqsContent = '';

    public bool $showConfirmTerms = false;

    public bool $showConfirmPrivacy = false;

    public bool $showConfirmFaqs = false;

    public ?string $successMessage = null;

    public function mount(): void
    {
        $terms = LegalPage::firstOrCreate(
            ['slug' => 'terms'],
            ['title' => 'Terms of Service', 'content' => '']
        );
        $privacy = LegalPage::firstOrCreate(
            ['slug' => 'privacy'],
            ['title' => 'Privacy Policy', 'content' => '']
        );
        $faqs = FaqsContent::query()->firstOrCreate(
            ['id' => 1],
            ['content' => '']
        );

        $this->termsContent = $terms->content ?? '';
        $this->privacyContent = $privacy->content ?? '';
        $this->faqsContent = $faqs->content ?? '';
    }

    public function confirmSaveTerms(): void
    {
        $this->showConfirmTerms = true;
    }

    public function saveTerms(): void
    {
        $this->validateOnly('termsContent');

        LegalPage::query()
            ->where('slug', 'terms')
            ->update(['content' => $this->termsContent]);

        $this->showConfirmTerms = false;
        $this->successMessage = 'Terms of Service saved.';
    }

    public function confirmSavePrivacy(): void
    {
        $this->showConfirmPrivacy = true;
    }

    public function savePrivacy(): void
    {
        $this->validateOnly('privacyContent');

        LegalPage::query()
            ->where('slug', 'privacy')
            ->update(['content' => $this->privacyContent]);

        $this->showConfirmPrivacy = false;
        $this->successMessage = 'Privacy Policy saved.';
    }

    public function confirmSaveFaqs(): void
    {
        $this->showConfirmFaqs = true;
    }

    public function saveFaqs(): void
    {
        $this->validateOnly('faqsContent');

        FaqsContent::query()->updateOrCreate(
            ['id' => 1],
            ['content' => $this->faqsContent]
        );

        $this->showConfirmFaqs = false;
        $this->successMessage = 'FAQs saved.';
    }

    public function render(): mixed
    {
        return view('livewire.admin.content-management');
    }
}
