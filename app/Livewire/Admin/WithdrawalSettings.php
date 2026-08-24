<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\PlatformSettings;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Withdrawal Settings'])]
class WithdrawalSettings extends Component
{
    public string $uiState = 'normal';

    public string $minBitcoin = '';

    public string $minTrc20 = '';

    public string $minErc20 = '';

    public bool $showConfirmModal = false;

    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState !== 'error') {
            $this->loadSettings();
        }
    }

    private function loadSettings(): void
    {
        $settings = PlatformSettings::instance();

        $this->minBitcoin = (string) $settings->withdrawal_min_usd_bitcoin;
        $this->minTrc20 = (string) $settings->withdrawal_min_usd_usdt_trc20;
        $this->minErc20 = (string) $settings->withdrawal_min_usd_usdt_erc20;
    }

    public function confirmSave(): void
    {
        $this->showConfirmModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'minBitcoin' => ['required', 'numeric', 'min:0.01'],
            'minTrc20' => ['required', 'numeric', 'min:0.01'],
            'minErc20' => ['required', 'numeric', 'min:0.01'],
        ], [
            'minBitcoin.min' => 'USD withdrawal minimum must be greater than $0.',
            'minTrc20.min' => 'USD withdrawal minimum must be greater than $0.',
            'minErc20.min' => 'USD withdrawal minimum must be greater than $0.',
        ]);

        PlatformSettings::instance()->update([
            'withdrawal_min_usd_bitcoin' => $validated['minBitcoin'],
            'withdrawal_min_usd_usdt_trc20' => $validated['minTrc20'],
            'withdrawal_min_usd_usdt_erc20' => $validated['minErc20'],
        ]);

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
