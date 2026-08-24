<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Website Owner Detail'])]
class WebsiteOwnerDetail extends Component
{
    public string $uiState = 'normal';

    public ?User $ownerRecord = null;

    public string $withdrawalMode = 'approval';

    public ?string $feeOverride = null;

    public bool $showModeModal = false;

    public bool $showFeeModal = false;

    public ?string $successMessage = null;

    public function mount(int $owner): void
    {
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState !== 'error') {
            $this->loadOwner($owner);
        }
    }

    private function loadOwner(int $ownerId): void
    {
        $owner = User::query()
            ->where('role', 'owner')
            ->where('is_admin', false)
            ->find($ownerId);

        if ($owner === null) {
            $this->uiState = 'not-found';

            return;
        }

        $this->ownerRecord = $owner;
        $this->withdrawalMode = $owner->withdrawal_mode ?? 'approval';
        $this->feeOverride = $owner->deposit_fee_override !== null ? (string) $owner->deposit_fee_override : null;
    }

    #[Computed]
    public function withdrawalModeLabel(): string
    {
        return $this->withdrawalMode === 'instant' ? 'Instant' : 'Administrator Approval';
    }

    public function confirmSaveMode(): void
    {
        $this->showModeModal = true;
    }

    public function saveMode(): void
    {
        $validated = $this->validate([
            'withdrawalMode' => ['required', 'in:instant,approval'],
        ]);

        $this->ownerRecord?->update(['withdrawal_mode' => $validated['withdrawalMode']]);

        $this->showModeModal = false;
        $this->successMessage = "Withdrawal mode updated to {$this->withdrawalModeLabel}.";
    }

    public function confirmSaveFee(): void
    {
        $this->showFeeModal = true;
    }

    public function saveFee(): void
    {
        $validated = $this->validate([
            'feeOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $value = $validated['feeOverride'];
        if ($value === null || $value === '' || (float) $value === 0.0) {
            $this->ownerRecord?->update(['deposit_fee_override' => null]);
            $this->feeOverride = null;
            $this->showFeeModal = false;
            $this->successMessage = 'Fee override removed. This owner will use the platform default fee.';

            return;
        }

        $this->ownerRecord?->update(['deposit_fee_override' => $value]);
        $this->showFeeModal = false;
        $this->successMessage = "Deposit fee override set to {$value}%.";
    }

    public function retry(): void
    {
        $this->successMessage = null;
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState === 'error' || $this->ownerRecord === null) {
            return;
        }

        $this->loadOwner($this->ownerRecord->id);
    }

    public function render(): mixed
    {
        return view('livewire.admin.website-owner-detail');
    }
}
