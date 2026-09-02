<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\PlatformSettings as PlatformSettingsModel;
use Illuminate\Validation\ValidationException;
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

    public string $apiRequestsPerMinute = '';

    public string $profitAddressBitcoin = '';

    public string $profitAddressUsdtTrc20 = '';

    public string $profitAddressUsdtErc20 = '';

    public string $profitWarnFeePercent = '';

    public string $profitBlockFeePercent = '';

    public bool $showFeeModal = false;

    public bool $showMinDepositModal = false;

    public bool $showModeModal = false;

    public bool $showApiRequestsModal = false;

    public bool $showProfitModal = false;

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
        $this->apiRequestsPerMinute = (string) $settings->api_requests_per_minute;
        $this->profitAddressBitcoin = (string) ($settings->profit_address_bitcoin ?? '');
        $this->profitAddressUsdtTrc20 = (string) ($settings->profit_address_usdt_trc20 ?? '');
        $this->profitAddressUsdtErc20 = (string) ($settings->profit_address_usdt_erc20 ?? '');
        $this->profitWarnFeePercent = (string) $settings->profit_payout_warn_fee_percent;
        $this->profitBlockFeePercent = (string) $settings->profit_payout_block_fee_percent;
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

    public function confirmSaveApiRequests(): void
    {
        $this->showApiRequestsModal = true;
    }

    public function saveApiRequests(): void
    {
        $validated = $this->validate([
            'apiRequestsPerMinute' => ['required', 'integer', 'min:1'],
        ]);

        PlatformSettingsModel::instance()->update([
            'api_requests_per_minute' => $validated['apiRequestsPerMinute'],
        ]);

        $this->showApiRequestsModal = false;
        $this->successMessage = 'API request limit updated.';
    }

    public function confirmSaveProfit(): void
    {
        $this->showProfitModal = true;
    }

    public function saveProfit(): void
    {
        $this->profitAddressBitcoin = trim($this->profitAddressBitcoin);
        $this->profitAddressUsdtTrc20 = trim($this->profitAddressUsdtTrc20);
        $this->profitAddressUsdtErc20 = trim($this->profitAddressUsdtErc20);

        $validated = $this->validate([
            'profitAddressBitcoin' => ['nullable', 'string', 'max:128', 'regex:/^(bc1[ac-hj-np-z02-9]{25,62}|[13][a-km-zA-HJ-NP-Z1-9]{25,34})$/'],
            'profitAddressUsdtTrc20' => ['nullable', 'string', 'max:128', 'regex:/^T[1-9A-HJ-NP-Za-km-z]{33}$/'],
            'profitAddressUsdtErc20' => ['nullable', 'string', 'max:128', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'profitWarnFeePercent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'profitBlockFeePercent' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ], [
            'profitAddressBitcoin.regex' => "This doesn't look like a valid Bitcoin address.",
            'profitAddressUsdtTrc20.regex' => "This doesn't look like a valid USDT (TRC20) address.",
            'profitAddressUsdtErc20.regex' => "This doesn't look like a valid USDT (ERC20) address.",
        ]);

        if (bccomp((string) $validated['profitWarnFeePercent'], (string) $validated['profitBlockFeePercent'], 8) >= 0) {
            throw ValidationException::withMessages(['profitWarnFeePercent' => 'Warning threshold must be lower than the block threshold.']);
        }

        PlatformSettingsModel::instance()->update([
            'profit_address_bitcoin' => $validated['profitAddressBitcoin'] ?: null,
            'profit_address_usdt_trc20' => $validated['profitAddressUsdtTrc20'] ?: null,
            'profit_address_usdt_erc20' => $validated['profitAddressUsdtErc20'] ?: null,
            'profit_payout_warn_fee_percent' => $validated['profitWarnFeePercent'],
            'profit_payout_block_fee_percent' => $validated['profitBlockFeePercent'],
        ]);

        $this->showProfitModal = false;
        $this->successMessage = 'Profit payout settings saved.';
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
