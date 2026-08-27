<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Services\Blockchain\GasTreasuryService;
use Illuminate\Support\Collection;
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

    private const NETWORKS = [
        'bitcoin' => ['label' => 'Bitcoin', 'symbol' => 'BTC', 'native' => 'BTC', 'decimals' => 8, 'slug' => 'bitcoin'],
        'usdt_trc20' => ['label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'native' => 'TRX', 'decimals' => 2, 'slug' => 'usdt-trc20'],
        'usdt_erc20' => ['label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'native' => 'ETH', 'decimals' => 2, 'slug' => 'usdt-erc20'],
        'usdt_base' => ['label' => 'USDT (Base)', 'symbol' => 'USDT', 'native' => 'Base ETH', 'decimals' => 2, 'slug' => 'usdt-erc20'],
    ];

    public function mount(GasTreasuryService $gasTreasury): void
    {
        $this->uiState = request()->query('state', 'normal');

        TreasuryWallet::query()->whereIn('network', $this->gasNetworks())->pluck('network')
            ->each(fn (string $network) => $this->loadPolicy($gasTreasury->policy($network)));
    }

    #[Computed]
    public function wallets(): Collection
    {
        return TreasuryWallet::query()->orderBy('network')->get();
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

    public function networkMeta(string $networkKey): array
    {
        return self::NETWORKS[$networkKey] ?? ['label' => $networkKey, 'symbol' => '', 'native' => '', 'decimals' => 8, 'slug' => $networkKey];
    }

    public function formattedAmount(float $amount, int $decimals): string
    {
        return number_format($amount, $decimals);
    }

    public function usdValue(float $cryptoAmount, string $networkKey): string
    {
        $valuation = UsdValuation::query()->where('network', $networkKey)->first();

        return number_format($cryptoAmount * (float) ($valuation?->conversion_value ?? 0), 2);
    }

    public function isLow(TreasuryWallet $wallet): bool
    {
        return isset($this->policies[$wallet->network])
            && $wallet->native_balance !== null
            && bccomp((string) $wallet->native_balance, (string) $this->policies[$wallet->network]['reserve_threshold'], 8) < 0;
    }

    public function savePolicy(string $network): void
    {
        abort_unless(in_array($network, $this->gasNetworks(), true), 404);

        $data = $this->validate([
            "policies.$network.reserve_threshold" => ['required', 'numeric', 'min:0'],
            "policies.$network.top_up_amount" => ['required', 'numeric', 'gt:0'],
            "policies.$network.max_top_up" => ['required', 'numeric', 'gt:0'],
            "policies.$network.alert_cooldown" => ['required', 'integer', 'min:1', 'max:10080'],
        ])['policies'][$network];

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

    public function refreshWallet(int $walletId, GasTreasuryService $gasTreasury): void
    {
        $wallet = TreasuryWallet::query()->findOrFail($walletId);
        $this->message = $gasTreasury->refreshTreasuryWallet($wallet) === null
            ? 'Wallet balance could not be refreshed.'
            : $this->networkMeta($wallet->network)['label'].' balance refreshed.';
        unset($this->wallets);
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    private function loadPolicy(GasPolicy $policy): void
    {
        $this->policies[$policy->network] = [
            'reserve_threshold' => (string) $policy->reserve_threshold,
            'top_up_amount' => (string) $policy->top_up_amount,
            'max_top_up' => (string) $policy->max_top_up,
            'alert_cooldown' => $policy->alert_cooldown,
            'manual_paused' => $policy->manual_paused,
        ];
    }

    private function gasNetworks(): array
    {
        return ['usdt_erc20', 'usdt_base', 'usdt_trc20'];
    }

    public function render(): mixed
    {
        return view('livewire.admin.treasury-overview');
    }
}
