<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\PlatformSettings as PlatformSettingsModel;
use App\Support\Network;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Platform Settings'])]
class PlatformSettings extends Component
{
    public string $uiState = 'normal';

    public string $depositFee = '';

    /**
     * @var array<string, string> Minimum deposit per network key.
     */
    public array $minDeposits = [];

    public string $defaultWithdrawalMode = 'approval';

    public string $apiRequestsPerMinute = '';

    /**
     * @var array<string, string|null> Profit payout address per network key.
     */
    public array $profitAddresses = [];

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

    #[Computed]
    public function networks(): array
    {
        return Network::presentAll(enabledOnly: true);
    }

    private function loadSettings(): void
    {
        $settings = PlatformSettingsModel::instance();

        $this->depositFee = (string) $settings->global_deposit_fee_percent;
        $this->defaultWithdrawalMode = $settings->default_withdrawal_mode;
        $this->apiRequestsPerMinute = (string) $settings->api_requests_per_minute;
        $this->profitWarnFeePercent = (string) $settings->profit_payout_warn_fee_percent;
        $this->profitBlockFeePercent = (string) $settings->profit_payout_block_fee_percent;

        $this->minDeposits = [];
        $this->profitAddresses = [];

        foreach (Network::enabledKeys() as $key) {
            $setting = PlatformSettingsModel::networkSetting($key);
            $this->minDeposits[$key] = (string) $setting->min_deposit;
            $this->profitAddresses[$key] = (string) ($setting->profit_address ?? '');
        }
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
            'minDeposits.*' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated['minDeposits'] as $key => $minimum) {
            PlatformSettingsModel::networkSetting($key)->update(['min_deposit' => $minimum]);
        }

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
            'api_requests_per_minute' => (int) $validated['apiRequestsPerMinute'],
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
        $rules = [];
        $messages = [];

        foreach (Network::enabledKeys() as $key) {
            $this->profitAddresses[$key] = trim((string) ($this->profitAddresses[$key] ?? ''));
            $groupMeta = config('networks.address_groups.'.Network::addressGroup($key), []);
            $rules["profitAddresses.{$key}"] = ['nullable', 'string', 'max:128', 'regex:'.$groupMeta['address_regex']];
            $messages["profitAddresses.{$key}.regex"] = "This doesn't look like a valid ".Network::label($key).' address.';
        }

        $validated = $this->validate($rules + [
            'profitWarnFeePercent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'profitBlockFeePercent' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ], $messages);

        if (bccomp((string) $validated['profitWarnFeePercent'], (string) $validated['profitBlockFeePercent'], 8) >= 0) {
            throw ValidationException::withMessages(['profitWarnFeePercent' => 'Warning threshold must be lower than the block threshold.']);
        }

        foreach (Network::enabledKeys() as $key) {
            $address = trim((string) ($validated['profitAddresses'][$key] ?? ''));

            PlatformSettingsModel::networkSetting($key)->update([
                'profit_address' => $address === '' ? null : $address,
            ]);
        }

        PlatformSettingsModel::instance()->update([
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
