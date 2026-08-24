<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Faq;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.dashboard.layout', ['title' => 'FAQs'])]
class FaqManager extends Component
{
    use WithFileUploads;

    #[Validate('required|string|max:255')]
    public string $question = '';

    #[Validate('required|string')]
    public string $answer = '';

    #[Validate('nullable|image|max:5120')]
    public $image = null;

    public ?int $editingId = null;

    public string $editQuestion = '';

    public string $editAnswer = '';

    #[Validate('nullable|image|max:5120')]
    public $editImage = null;

    public bool $removeEditImage = false;

    public function create(): void
    {
        $this->validate();

        Faq::create([
            'question' => $this->question,
            'answer' => $this->answer,
            'image_path' => $this->image?->store('faqs', 'public'),
            'position' => (Faq::max('position') ?? 0) + 1,
        ]);

        $this->reset(['question', 'answer', 'image']);

        session()->flash('status', 'FAQ added.');
    }

    public function startEdit(int $id): void
    {
        $faq = Faq::findOrFail($id);

        $this->editingId = $faq->id;
        $this->editQuestion = $faq->question;
        $this->editAnswer = $faq->answer;
        $this->editImage = null;
        $this->removeEditImage = false;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->reset(['editQuestion', 'editAnswer', 'editImage', 'removeEditImage']);
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editQuestion' => 'required|string|max:255',
            'editAnswer' => 'required|string',
            'editImage' => 'nullable|image|max:5120',
        ]);

        if ($this->editingId === null) {
            return;
        }

        $faq = Faq::findOrFail($this->editingId);

        $imagePath = $faq->image_path;

        if ($this->editImage) {
            $imagePath = $this->editImage->store('faqs', 'public');
        } elseif ($this->removeEditImage) {
            $imagePath = null;
        }

        $faq->update([
            'question' => $this->editQuestion,
            'answer' => $this->editAnswer,
            'image_path' => $imagePath,
        ]);

        $this->cancelEdit();

        session()->flash('status', 'FAQ updated.');
    }

    public function delete(int $id): void
    {
        Faq::destroy($id);

        session()->flash('status', 'FAQ removed.');
    }

    public function moveUp(int $id): void
    {
        $this->swapWithNeighbor($id, 'up');
    }

    public function moveDown(int $id): void
    {
        $this->swapWithNeighbor($id, 'down');
    }

    public function render(): mixed
    {
        return view('livewire.admin.faq-manager', [
            'faqs' => $this->faqs(),
        ]);
    }

    /** @return Collection<int, Faq> */
    #[Computed]
    private function faqs(): Collection
    {
        return Faq::orderBy('position')->orderBy('id')->get();
    }

    private function swapWithNeighbor(int $id, string $direction): void
    {
        $faqs = Faq::orderBy('position')->orderBy('id')->get();
        $index = $faqs->search(fn (Faq $faq) => $faq->id === $id);

        if ($index === false) {
            return;
        }

        $neighborIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if (! $faqs->has($neighborIndex)) {
            return;
        }

        $current = $faqs->get($index);
        $neighbor = $faqs->get($neighborIndex);

        [$currentPosition, $neighborPosition] = [$current->position, $neighbor->position];

        $current->update(['position' => $neighborPosition]);
        $neighbor->update(['position' => $currentPosition]);
    }
}
