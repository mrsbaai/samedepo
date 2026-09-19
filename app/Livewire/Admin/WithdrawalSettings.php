<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\PlatformSettings;
use App\Support\Network;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Withdrawal Settings'])]
class WithdrawalSettings extends Component
{
    public string $uiState = 'normal';

    /**
     * @var array<string, string> USD withdrawal minimum per network key.
     */
    public array $minimums = [];

    public bool $showConfirmModal = false;

    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState !== 'error') {
            $this->loadSettings();
        }
    }

    #[Computed]
    public function networks(): array
    {
        return Network::presentAll(enabledOnly: true);
    }

    private function loadSettings(): void
    {
        $this->minimums = [];

        foreach (Network::enabledKeys() as $key) {
            $this->minimums[$key] = (string) PlatformSettings::networkSetting($key)->withdrawal_min_usd;
        }
    }

    public function confirmSave(): void
    {
        $this->showConfirmModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'minimums.*' => ['required', 'numeric', 'min:0.01'],
        ], [
            'minimums.*.min' => 'USD withdrawal minimum must be greater than $0.',
        ]);

        foreach ($validated['minimums'] as $key => $minimum) {
            PlatformSettings::networkSetting($key)->update(['withdrawal_min_usd' => $minimum]);
        }

        $this->showConfirmModal = false;
        $this->successMessage = 'Withdrawal minimums updated.';
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
        $this->successMessage = null;

        if ($this->uiState === 'error') {
            return;
        }

        $this->loadSettings();
    }

    public function render(): mixed
    {
        return view('livewire.admin.withdrawal-settings');
    }
}
