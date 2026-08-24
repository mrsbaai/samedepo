<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\PlatformSettings as PlatformSettingsModel;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Platform Settings'])]
class PlatformSettings extends Component
{
    public string $uiState = 'normal';

    public string $depositFee = '';

    public string $minDepositBitcoin = '';

    public string $minDepositTrc20 = '';

    public string $minDepositErc20 = '';

    public string $defaultWithdrawalMode = 'approval';

    public bool $showFeeModal = false;

    public bool $showMinDepositModal = false;

    public bool $showModeModal = false;

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
        $settings = PlatformSettingsModel::instance();

        $this->depositFee = (string) $settings->global_deposit_fee_percent;
        $this->minDepositBitcoin = (string) $settings->min_deposit_bitcoin;
        $this->minDepositTrc20 = (string) $settings->min_deposit_usdt_trc20;
        $this->minDepositErc20 = (string) $settings->min_deposit_usdt_erc20;
        $this->defaultWithdrawalMode = $settings->default_withdrawal_mode;
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load platform settings. Please try again.";
    }

    public function confirmSaveFee(): void
    {
        $this->showFeeModal = true;
    }

    public function saveFee(): void
    {
        $validated = $this->validate([
            'depositFee' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        PlatformSettingsModel::instance()->update([
            'global_deposit_fee_percent' => $validated['depositFee'],
        ]);

        $this->showFeeModal = false;
        $this->successMessage = "samedepo deducts a {$validated['depositFee']}% fee before crediting confirmed deposits.";
    }

    public function confirmSaveMinDeposit(): void
    {
        $this->showMinDepositModal = true;
    }

    public function saveMinDeposit(): void
    {
        $validated = $this->validate([
            'minDepositBitcoin' => ['required', 'numeric', 'min:0'],
            'minDepositTrc20' => ['required', 'numeric', 'min:0'],
            'minDepositErc20' => ['required', 'numeric', 'min:0'],
        ]);

        PlatformSettingsModel::instance()->update([
            'min_deposit_bitcoin' => $validated['minDepositBitcoin'],
            'min_deposit_usdt_trc20' => $validated['minDepositTrc20'],
            'min_deposit_usdt_erc20' => $validated['minDepositErc20'],
        ]);

        $this->showMinDepositModal = false;
        $this->successMessage = 'Minimum deposit sizes updated.';
    }

    public function confirmSaveMode(): void
    {
        $this->showModeModal = true;
    }

    public function saveMode(): void
    {
        $validated = $this->validate([
            'defaultWithdrawalMode' => ['required', 'in:instant,approval'],
        ]);

        PlatformSettingsModel::instance()->update([
            'default_withdrawal_mode' => $validated['defaultWithdrawalMode'],
        ]);

        $this->showModeModal = false;
        $label = $validated['defaultWithdrawalMode'] === 'instant' ? 'Instant' : 'Administrator Approval';
        $this->successMessage = "Default withdrawal mode set to {$label} for new accounts.";
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
        return view('livewire.admin.platform-settings');
    }
}
