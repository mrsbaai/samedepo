<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Deposit;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Services\Blockchain\Energy\TronSaveClient;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\TreasuryPayoutService;
use App\Services\Blockchain\TreasuryProfitCalculator;
use App\Support\ExplorerUrl;
use App\Support\Network;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Treasury Overview'])]
class TreasuryOverview extends Component
{
    public string $uiState = 'normal';

    public array $policies = [];

    public ?string $message = null;

    public bool $payoutModal = false;

    public string $payoutNetwork = '';

    public string $payoutDestination = '';

    public string $payoutAmount = '';

    public string $payoutStep = 'form';

    public ?string $payoutTxHash = null;

    public ?string $payoutMessage = null;

    public array $payoutPreview = [];

    public function mount(GasTreasuryService $gasTreasury): void
    {
        $this->uiState = request()->query('state', 'normal');

        TreasuryWallet::query()->whereIn('network', Network::enabledKeys())->pluck('network')
            ->map(fn (string $network) => Network::nativeKey($network))
            ->unique()
            ->filter(fn (string $nativeKey) => isset(Network::natives()[$nativeKey]))
            ->each(fn (string $nativeKey) => $this->loadPolicy($gasTreasury->policy($nativeKey)));

        $requested = (string) request()->query('payout', '');
        if (Network::exists($requested)
            && ($this->profitAddresses[$requested] ?? null)
            && bccomp($this->profit['networks'][$requested]['withdrawable'], '0', 8) > 0) {
            $this->openPayout($requested);
        }
    }

    #[Computed]
    public function profit(): array
    {
        return app(TreasuryProfitCalculator::class)->summary();
    }

    #[Computed]
    public function profitAddresses(): array
    {
        $addresses = [];

        foreach (Network::enabledKeys() as $key) {
            $addresses[$key] = PlatformSettings::networkSetting($key)->profit_address;
        }

        return $addresses;
    }

    #[Computed]
    public function wallets(): Collection
    {
        return TreasuryWallet::query()->orderBy('network')->get();
    }

    #[Computed]
    public function networkMetrics(): Collection
    {
        return $this->wallets->mapWithKeys(function (TreasuryWallet $wallet): array {
            $nativeKey = Network::nativeKey($wallet->network);

            $unsweptAmount = $this->decimal(Deposit::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->where('status', 'credited')
                ->whereNull('swept_at')
                ->sum('gross_amount'));

            $unsweptAddresses = Deposit::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->where('status', 'credited')
                ->whereNull('swept_at')
                ->distinct()
                ->count('deposit_address_id');

            $feeSum = $this->decimal(LedgerEntry::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->where('reason', 'fee')
                ->sum('amount'));
            $revenueFee = bccomp($feeSum, '0', 8) < 0 ? bcsub('0', $feeSum, 8) : $feeSum;

            $networkFeeSum = $this->decimal(LedgerEntry::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->whereIn('reason', ['network_fee', 'network_fee_adjustment', 'consolidation_fee'])
                ->sum('amount'));
            $revenueNetworkFee = bccomp($networkFeeSum, '0', 8) < 0 ? bcsub('0', $networkFeeSum, 8) : '0.00000000';

            $pendingWithdrawalsCount = Withdrawal::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->whereIn('status', ['pending', 'approved'])
                ->count();

            $pendingWithdrawalsSum = $this->decimal(Withdrawal::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->whereIn('status', ['pending', 'approved'])
                ->sum('gross_amount'));

            return [
                $wallet->network => [
                    'address' => $wallet->address,
                    'explorer_url' => $this->explorerUrl('address', $wallet->network, $wallet->address),
                    'available_funds' => (string) $wallet->available_funds,
                    'available_funds_usd' => $this->usdValue($wallet->available_funds, $wallet->network),
                    'native_balance' => (string) ($wallet->native_balance ?? '0.00000000'),
                    'native_balance_usd' => $this->usdValue($wallet->native_balance ?? 0, $nativeKey),
                    'unswept_amount' => $unsweptAmount,
                    'unswept_usd' => $this->usdValue($unsweptAmount, $wallet->network),
                    'unswept_addresses' => $unsweptAddresses,
                    'revenue_fee' => $revenueFee,
                    'revenue_fee_usd' => $this->usdValue($revenueFee, $wallet->network),
                    'revenue_network_fee' => $revenueNetworkFee,
                    'revenue_network_fee_usd' => $this->usdValue($revenueNetworkFee, $wallet->network),
                    'pending_withdrawals_count' => $pendingWithdrawalsCount,
                    'pending_withdrawals_sum' => $pendingWithdrawalsSum,
                    'pending_withdrawals_usd' => $this->usdValue($pendingWithdrawalsSum, $wallet->network),
                ],
            ];
        });
    }

    #[Computed]
    public function recentSweeps(): Collection
    {
        return TreasurySweep::query()->latest()->limit(10)->get();
    }

    #[Computed]
    public function recentPayouts(): Collection
    {
        return TreasuryPayout::query()->latest()->limit(10)->get();
    }

    #[Computed]
    public function topups(): Collection
    {
        return GasTopup::query()->whereIn('status', ['pending', 'broadcast', 'failed'])->latest()->limit(10)->get();
    }

    #[Computed]
    public function expenses(): Collection
    {
        return GasExpense::query()->latest()->limit(10)->get();
    }

    #[Computed]
    public function energyFloat(): ?array
    {
        if (($this->policies['native_trx']['energy_mode'] ?? 'burn') !== 'rent') {
            return null;
        }

        $info = Cache::remember('tronsave-user-info', 300, fn () => app(TronSaveClient::class)->userInfo());

        if ($info === null) {
            return null;
        }

        return [
            'balance_trx' => bcdiv((string) ($info['balance'] ?? '0'), '1000000', 8),
            'deposit_address' => $info['depositAddress'] ?? null,
        ];
    }

    public function networkMeta(string $networkKey): array
    {
        if (Network::exists($networkKey)) {
            return Network::present($networkKey) + ['native' => Network::nativeSymbol($networkKey)];
        }

        $nativeSymbol = Network::nativeSymbol($networkKey);

        return [
            'key' => $networkKey,
            'label' => $nativeSymbol.' gas',
            'symbol' => $nativeSymbol,
            'native' => $nativeSymbol,
            'decimals' => 8,
            'slug' => $networkKey,
            'icon' => '',
        ];
    }

    public function formattedAmount(float $amount, int $decimals): string
    {
        return number_format($amount, $decimals);
    }

    public function usdValue(float|string $cryptoAmount, string $networkKey): string
    {
        return number_format((float) $this->usdValueRaw($this->decimal($cryptoAmount), $networkKey), 2);
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 8, '.', '');
    }

    private function usdValueRaw(string $cryptoAmount, string $networkKey): string
    {
        $valuation = UsdValuation::query()->where('network', $networkKey)->value('conversion_value');

        return bcmul($cryptoAmount, (string) ($valuation ?? 0), 8);
    }

    public function isLow(TreasuryWallet $wallet): bool
    {
        return isset($this->policies[Network::nativeKey($wallet->network)])
            && $wallet->native_balance !== null
            && bccomp((string) $wallet->native_balance, (string) $this->policies[Network::nativeKey($wallet->network)]['reserve_threshold'], 8) < 0;
    }

    public function savePolicy(string $network): void
    {
        abort_unless(in_array($network, $this->gasNetworks(), true), 404);

        $rules = [
            "policies.$network.reserve_threshold" => ['required', 'numeric', 'min:0'],
            "policies.$network.top_up_amount" => ['required', 'numeric', 'gt:0'],
            "policies.$network.max_top_up" => ['required', 'numeric', 'gt:0'],
            "policies.$network.alert_cooldown" => ['required', 'integer', 'min:1', 'max:10080'],
        ];

        if (Network::nativeChain($network) === 'tron') {
            $rules += [
                "policies.$network.energy_mode" => ['required', 'string', 'in:burn,rent'],
                "policies.$network.rent_max_price_sun" => ['required', 'integer', 'min:1'],
                "policies.$network.rent_duration_sec" => ['required', 'integer', 'min:300'],
                "policies.$network.rent_float_alert_trx" => ['required', 'numeric', 'min:0'],
            ];
        }

        $data = $this->validate($rules)['policies'][$network];

        if (bccomp((string) $data['top_up_amount'], (string) $data['max_top_up'], 8) > 0) {
            throw ValidationException::withMessages(["policies.$network.top_up_amount" => 'Top-up amount must not exceed the maximum top-up.']);
        }

        GasPolicy::query()->where('network', $network)->firstOrFail()->update($data);
        $this->message = $this->networkMeta($network)['label'].' gas policy saved.';
    }

    public function togglePause(string $network): void
    {
        abort_unless(in_array($network, $this->gasNetworks(), true), 404);
        $policy = GasPolicy::query()->where('network', $network)->firstOrFail();
        $policy->update(['manual_paused' => ! $policy->manual_paused]);
        $this->loadPolicy($policy->refresh());
        $this->message = $this->networkMeta($network)['label'].($policy->manual_paused ? ' gas operations paused.' : ' gas operations resumed.');
    }

    public function refreshTreasuryData(GasTreasuryService $gasTreasury): void
    {
        $gasTreasury->refreshStaleTreasuryWallets();

        unset(
            $this->profit,
            $this->profitAddresses,
            $this->wallets,
            $this->networkMetrics,
            $this->recentSweeps,
            $this->recentPayouts,
            $this->topups,
            $this->expenses,
        );
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    public function openPayout(string $network): void
    {
        abort_unless(Network::exists($network), 404);

        $this->payoutModal = true;
        $this->payoutNetwork = $network;
        $this->payoutDestination = (string) ($this->profitAddresses[$network] ?? '');
        $this->payoutAmount = $this->formatWithdrawableInput($this->profit['networks'][$network]['withdrawable'] ?? '0');
        $this->payoutStep = 'form';
        $this->payoutTxHash = null;
        $this->payoutMessage = null;
        $this->payoutPreview = [];
    }

    public function previewPayout(): void
    {
        $this->validate([
            'payoutAmount' => ['required', 'numeric', 'gt:0'],
        ]);

        $withdrawable = $this->profit['networks'][$this->payoutNetwork]['withdrawable'] ?? '0.00000000';

        if (bccomp((string) $this->payoutAmount, $withdrawable, 8) > 0) {
            throw ValidationException::withMessages(['payoutAmount' => 'Amount exceeds withdrawable profit.']);
        }

        $this->payoutPreview = app(TreasuryPayoutService::class)->preview(
            $this->payoutNetwork,
            (string) $this->payoutAmount,
            $this->payoutDestination,
        );

        $this->payoutStep = 'confirm';
    }

    public function confirmPayout(): void
    {
        $this->payoutPreview = app(TreasuryPayoutService::class)->preview(
            $this->payoutNetwork,
            (string) $this->payoutAmount,
            $this->payoutDestination,
        );

        if (($this->payoutPreview['level'] ?? 'block') === 'block') {
            $this->payoutStep = 'error';
            $this->payoutMessage = $this->payoutPreview['message'] ?? 'This payout cannot be sent right now.';

            return;
        }

        $payout = TreasuryPayout::create([
            'network' => $this->payoutNetwork,
            'destination_address' => $this->payoutDestination,
            'amount' => $this->payoutAmount,
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);

        $success = app(TreasuryPayoutService::class)->send($payout);

        if ($success) {
            $this->payoutStep = 'success';
            $this->payoutTxHash = $payout->tx_hash;
            $this->message = 'Payout sent. It will be marked confirmed once the network confirms it.';
            unset($this->profit, $this->wallets);
        } else {
            $this->payoutStep = 'error';
            $this->payoutMessage = $payout->error_message ?? 'Payout could not be sent.';
        }
    }

    public function resetPayout(): void
    {
        $this->payoutModal = false;
        $this->payoutStep = 'form';
        $this->payoutDestination = '';
        $this->payoutAmount = '';
        $this->payoutTxHash = null;
        $this->payoutMessage = null;
        $this->payoutPreview = [];
    }

    private function formatWithdrawableInput(string $amount): string
    {
        $trimmed = rtrim(rtrim($amount, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private function loadPolicy(GasPolicy $policy): void
    {
        $this->policies[$policy->network] = [
            'reserve_threshold' => (string) $policy->reserve_threshold,
            'top_up_amount' => (string) $policy->top_up_amount,
            'max_top_up' => (string) $policy->max_top_up,
            'alert_cooldown' => $policy->alert_cooldown,
            'manual_paused' => $policy->manual_paused,
            'energy_mode' => $policy->energy_mode,
            'rent_max_price_sun' => $policy->rent_max_price_sun,
            'rent_duration_sec' => $policy->rent_duration_sec,
            'rent_float_alert_trx' => (string) $policy->rent_float_alert_trx,
        ];
    }

    private function gasNetworks(): array
    {
        return array_keys(Network::natives());
    }

    public function explorerUrl(string $type, string $network, ?string $value): ?string
    {
        return ExplorerUrl::for($type, $network, $value);
    }

    public function render(): mixed
    {
        return view('livewire.admin.treasury-overview');
    }
}
