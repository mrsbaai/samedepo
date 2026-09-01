<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Deposit;
use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\LedgerEntry;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\TreasuryPayoutService;
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

    public bool $payoutModal = false;

    public string $payoutNetwork = 'bitcoin';

    public string $payoutDestination = '';

    public string $payoutAmount = '';

    public string $payoutStep = 'form';

    public ?string $payoutTxHash = null;

    public ?string $payoutMessage = null;

    private const NETWORKS = [
        'bitcoin' => ['label' => 'Bitcoin', 'symbol' => 'BTC', 'native' => 'BTC', 'decimals' => 8, 'slug' => 'bitcoin'],
        'usdt_trc20' => ['label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'native' => 'TRX', 'decimals' => 2, 'slug' => 'usdt-trc20'],
        'usdt_erc20' => ['label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'native' => 'ETH', 'decimals' => 2, 'slug' => 'usdt-erc20'],
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
    public function networkMetrics(): Collection
    {
        return $this->wallets->mapWithKeys(function (TreasuryWallet $wallet): array {
            $nativeKey = match ($wallet->network) {
                'bitcoin' => 'bitcoin',
                'usdt_trc20' => 'native_trx',
                'usdt_erc20' => 'native_eth',
                default => $wallet->network,
            };

            $unsweptAmount = (string) (Deposit::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->where('status', 'credited')
                ->whereNull('swept_at')
                ->sum('gross_amount') ?? '0.00000000');

            $unsweptAddresses = Deposit::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->where('status', 'credited')
                ->whereNull('swept_at')
                ->distinct()
                ->count('deposit_address_id');

            $feeSum = (string) (LedgerEntry::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->where('reason', 'fee')
                ->sum('amount') ?? '0.00000000');
            $revenueFee = bccomp($feeSum, '0', 8) < 0 ? bcsub('0', $feeSum, 8) : $feeSum;

            $networkFeeSum = (string) (LedgerEntry::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->where('reason', 'network_fee')
                ->sum('amount') ?? '0.00000000');
            $revenueNetworkFee = bccomp($networkFeeSum, '0', 8) < 0 ? bcsub('0', $networkFeeSum, 8) : $networkFeeSum;

            $pendingWithdrawalsCount = Withdrawal::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->whereIn('status', ['pending', 'approved'])
                ->count();

            $pendingWithdrawalsSum = (string) (Withdrawal::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->whereIn('status', ['pending', 'approved'])
                ->sum('gross_amount') ?? '0.00000000');

            return [
                $wallet->network => [
                    'address' => $wallet->address,
                    'explorer_url' => $this->explorerUrl('address', $wallet->network, $wallet->address),
                    'available_funds' => (string) $wallet->available_funds,
                    'available_funds_usd' => $this->usdValue((float) $wallet->available_funds, $wallet->network),
                    'native_balance' => (string) ($wallet->native_balance ?? '0.00000000'),
                    'native_balance_usd' => $this->usdValue((float) ($wallet->native_balance ?? 0), $nativeKey),
                    'unswept_amount' => $unsweptAmount,
                    'unswept_usd' => $this->usdValue((float) $unsweptAmount, $wallet->network),
                    'unswept_addresses' => $unsweptAddresses,
                    'revenue_fee' => $revenueFee,
                    'revenue_fee_usd' => $this->usdValue((float) $revenueFee, $wallet->network),
                    'revenue_network_fee' => $revenueNetworkFee,
                    'revenue_network_fee_usd' => $this->usdValue((float) $revenueNetworkFee, $wallet->network),
                    'pending_withdrawals_count' => $pendingWithdrawalsCount,
                    'pending_withdrawals_sum' => $pendingWithdrawalsSum,
                    'pending_withdrawals_usd' => $this->usdValue((float) $pendingWithdrawalsSum, $wallet->network),
                ],
            ];
        });
    }

    #[Computed]
    public function grandTotalUsd(): string
    {
        $total = '0.00000000';

        foreach ($this->wallets as $wallet) {
            $nativeKey = match ($wallet->network) {
                'bitcoin' => 'bitcoin',
                'usdt_trc20' => 'native_trx',
                'usdt_erc20' => 'native_eth',
                default => $wallet->network,
            };

            $total = bcadd($total, $this->usdValueRaw((string) $wallet->available_funds, $wallet->network), 8);
            $total = bcadd($total, $this->usdValueRaw((string) ($wallet->native_balance ?? 0), $nativeKey), 8);

            $unswept = (string) (Deposit::query()->withoutGlobalScope('owner')
                ->where('network', $wallet->network)
                ->where('status', 'credited')
                ->whereNull('swept_at')
                ->sum('gross_amount') ?? '0.00000000');

            $total = bcadd($total, $this->usdValueRaw($unswept, $wallet->network), 8);
        }

        return number_format((float) $total, 2);
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
        return number_format((float) $this->usdValueRaw((string) $cryptoAmount, $networkKey), 2);
    }

    private function usdValueRaw(string $cryptoAmount, string $networkKey): string
    {
        $valuation = UsdValuation::query()->where('network', $networkKey)->value('conversion_value');

        return bcmul($cryptoAmount, (string) ($valuation ?? 0), 8);
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

    public function openPayout(string $network): void
    {
        $this->payoutModal = true;
        $this->payoutNetwork = $network;
        $this->payoutDestination = '';
        $this->payoutAmount = '';
        $this->payoutStep = 'form';
        $this->payoutTxHash = null;
        $this->payoutMessage = null;
    }

    public function previewPayout(): void
    {
        $this->validate([
            'payoutDestination' => ['required', 'string', 'max:255'],
            'payoutAmount' => ['required', 'numeric', 'gt:0'],
        ]);

        $wallet = TreasuryWallet::query()->where('network', $this->payoutNetwork)->first();
        $available = (string) ($wallet?->available_funds ?? '0.00000000');

        if (bccomp((string) $this->payoutAmount, $available, 8) > 0) {
            throw ValidationException::withMessages(['payoutAmount' => 'Amount exceeds available funds.']);
        }

        $this->payoutStep = 'confirm';
    }

    public function confirmPayout(): void
    {
        $this->validate([
            'payoutDestination' => ['required', 'string', 'max:255'],
            'payoutAmount' => ['required', 'numeric', 'gt:0'],
        ]);

        $wallet = TreasuryWallet::query()->where('network', $this->payoutNetwork)->first();
        $available = (string) ($wallet?->available_funds ?? '0.00000000');

        if (bccomp((string) $this->payoutAmount, $available, 8) > 0) {
            $this->payoutMessage = 'Payout exceeds available funds.';

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
        return ['usdt_erc20', 'usdt_trc20'];
    }

    public function explorerUrl(string $type, string $network, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($network) {
            'bitcoin' => $type === 'address' ? 'https://mempool.space/address/'.$value : 'https://mempool.space/tx/'.$value,
            'usdt_trc20' => $type === 'address' ? 'https://tronscan.org/#/address/'.$value : 'https://tronscan.org/#/transaction/'.$value,
            'usdt_erc20' => $type === 'address' ? 'https://etherscan.io/address/'.$value : 'https://etherscan.io/tx/'.$value,
            default => null,
        };
    }

    public function render(): mixed
    {
        return view('livewire.admin.treasury-overview');
    }
}
