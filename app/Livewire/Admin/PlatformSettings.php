<?php

namespace App\Livewire\Admin;

use App\Models\PlatformSettings as PlatformSettingsModel;
use App\Services\Blockchain\NetworkProvisioner;
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
     * @var array<string, array{min_deposit: string, withdrawal_min_usd: string, sweep_min_usd: string, profit_address: string}> Per-network settings rows.
     */
    public array $rows = [];

    /**
     * @var array<string, bool> Effective enabled state per network key.
     */
    public array $enabledState = [];

    public ?string $toggleNetwork = null;

    public bool $toggleTarget = false;

    public string $defaultWithdrawalMode = 'approval';

    public string $apiRequestsPerMinute = '';

    public string $profitWarnFeePercent = '';

    public string $profitBlockFeePercent = '';

    public bool $showFeeModal = false;

    public bool $showModeModal = false;

    public bool $showApiRequestsModal = false;

    public bool $showProfitModal = false;

    public bool $showToggleModal = false;

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
        return Network::presentAll();
    }

    private function loadSettings(): void
    {
        $settings = PlatformSettingsModel::instance();

        $this->depositFee = (string) $settings->global_deposit_fee_percent;
        $this->defaultWithdrawalMode = $settings->default_withdrawal_mode;
        $this->apiRequestsPerMinute = (string) $settings->api_requests_per_minute;
        $this->profitWarnFeePercent = (string) $settings->profit_payout_warn_fee_percent;
        $this->profitBlockFeePercent = (string) $settings->profit_payout_block_fee_percent;

        $this->rows = [];
        $this->enabledState = [];

        foreach (Network::keys() as $key) {
            $setting = PlatformSettingsModel::networkSetting($key);
            $this->rows[$key] = [
                'min_deposit' => (string) $setting->min_deposit,
                'withdrawal_min_usd' => (string) $setting->withdrawal_min_usd,
                'sweep_min_usd' => (string) $setting->sweep_min_usd,
                'profit_address' => (string) ($setting->profit_address ?? ''),
            ];
            $this->enabledState[$key] = (bool) (Network::get($key)['enabled'] ?? false);
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

    public function saveNetworkRow(string $key): void
    {
        abort_unless(Network::exists($key), 404);

        $group = config('networks.address_groups.'.Network::addressGroup($key), []);
        $profitAddress = trim((string) ($this->rows[$key]['profit_address'] ?? ''));

        $validated = $this->validate([
            "rows.{$key}.min_deposit" => ['required', 'numeric', 'min:0'],
            "rows.{$key}.withdrawal_min_usd" => ['required', 'numeric', 'min:0'],
            "rows.{$key}.sweep_min_usd" => ['required', 'numeric', 'min:0'],
            "rows.{$key}.profit_address" => ['nullable', 'string', 'max:128', 'regex:'.$group['address_regex']],
        ], [
            "rows.{$key}.profit_address.regex" => 'Enter '.$group['address_hint'].'.',
        ])['rows'][$key];

        PlatformSettingsModel::networkSetting($key)->update([
            'min_deposit' => $validated['min_deposit'],
            'withdrawal_min_usd' => $validated['withdrawal_min_usd'],
            'sweep_min_usd' => $validated['sweep_min_usd'],
            'profit_address' => $profitAddress === '' ? null : $profitAddress,
        ]);

        $this->successMessage = Network::label($key).' settings saved.';
    }

    public function requestToggle(string $key): void
    {
        abort_unless(Network::exists($key), 404);

        $this->toggleNetwork = $key;
        $this->toggleTarget = ! ($this->enabledState[$key] ?? false);
        $this->showToggleModal = true;
    }

    public function confirmToggle(NetworkProvisioner $provisioner): void
    {
        $key = $this->toggleNetwork;
        abort_unless($key !== null && Network::exists($key), 404);

        PlatformSettingsModel::networkSetting($key)->update(['enabled' => $this->toggleTarget]);
        Network::flush();

        if ($this->toggleTarget) {
            $provisioner->provision($key);
        }

        $this->enabledState[$key] = $this->toggleTarget;
        $this->showToggleModal = false;
        $label = Network::label($key);
        $this->successMessage = $this->toggleTarget
            ? "{$label} enabled. Deposit addresses are issued and deposits are credited from now on."
            : "{$label} disabled. New deposits won't be credited. Existing balances and withdrawals are unaffected.";
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
        $validated = $this->validate([
            'profitWarnFeePercent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'profitBlockFeePercent' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ]);

        if (bccomp((string) $validated['profitWarnFeePercent'], (string) $validated['profitBlockFeePercent'], 8) >= 0) {
            throw ValidationException::withMessages(['profitWarnFeePercent' => 'Warning threshold must be lower than the block threshold.']);
        }

        PlatformSettingsModel::instance()->update([
            'profit_payout_warn_fee_percent' => $validated['profitWarnFeePercent'],
            'profit_payout_block_fee_percent' => $validated['profitBlockFeePercent'],
        ]);

        $this->showProfitModal = false;
        $this->successMessage = 'Profit payout thresholds saved.';
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
