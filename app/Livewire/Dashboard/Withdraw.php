<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Balance;
use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Models\WithdrawalAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Withdraw'])]
class Withdraw extends Component
{
    public string $uiState = 'normal';

    public string $network = 'usdt-trc20';

    public bool $showRequestModal = false;

    public bool $showCancelModal = false;

    public ?string $successMessage = null;

    private const NETWORKS = [
        'bitcoin' => ['slug' => 'bitcoin', 'label' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8],
        'usdt_trc20' => ['slug' => 'usdt-trc20', 'label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'decimals' => 2],
        'usdt_erc20' => ['slug' => 'usdt-erc20', 'label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'decimals' => 2],
    ];

    public function mount(?string $network = null): void
    {
        $this->network = $network ?? request()->query('network', 'usdt-trc20');

        if (! array_key_exists($this->networkKey(), self::NETWORKS)) {
            abort(404);
        }

        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState !== 'error' && ! $this->hasWithdrawalAddress()) {
            $this->redirectRoute('withdrawal-settings', navigate: true);
        }
    }

    private function networkKey(): string
    {
        return str_replace('-', '_', $this->network);
    }

    private function hasWithdrawalAddress(): bool
    {
        return WithdrawalAddress::query()->where('network', $this->networkKey())->exists();
    }

    #[Computed]
    public function networkMeta(): array
    {
        return self::NETWORKS[$this->networkKey()];
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load withdrawal data. Please try again.";
    }

    #[Computed]
    public function balanceModel(): Model
    {
        return Balance::query()->firstOrCreate(
            ['user_id' => Auth::id(), 'network' => $this->networkKey()],
            ['amount' => 0]
        );
    }

    #[Computed]
    public function usdValue(): float
    {
        $valuation = UsdValuation::query()->where('network', $this->networkKey())->first();

        if ($valuation === null) {
            return 0;
        }

        return (float) $this->balanceModel()->amount * (float) $valuation->conversion_value;
    }

    #[Computed]
    public function minimumUsd(): float
    {
        $settings = PlatformSettings::instance();
        $column = 'withdrawal_min_usd_'.$this->networkKey();

        return (float) ($settings->{$column} ?? 0);
    }

    #[Computed]
    public function eligible(): bool
    {
        return $this->usdValue() >= $this->minimumUsd();
    }

    #[Computed]
    public function withdrawalAddress(): ?WithdrawalAddress
    {
        return WithdrawalAddress::query()->where('network', $this->networkKey())->first();
    }

    #[Computed]
    public function mode(): string
    {
        return PlatformSettings::instance()->default_withdrawal_mode === 'instant' ? 'instant' : 'approval';
    }

    #[Computed]
    public function pendingWithdrawal(): ?Withdrawal
    {
        return Withdrawal::query()
            ->where('network', $this->networkKey())
            ->where('status', 'pending')
            ->latest()
            ->first();
    }

    public function formattedAmount(float $amount): string
    {
        return number_format($amount, $this->networkMeta()['decimals']);
    }

    public function formattedUsd(): string
    {
        return number_format($this->usdValue(), 2);
    }

    public function formattedMinimum(): string
    {
        return number_format($this->minimumUsd(), 2);
    }

    public function addressEnding(): string
    {
        $address = $this->withdrawalAddress()?->address ?? '';

        return $address !== '' ? '…'.substr($address, -6) : '';
    }

    public function confirmRequest(): void
    {
        $this->showRequestModal = true;
    }

    public function requestWithdrawal(): void
    {
        $this->authorizeRequest();

        DB::transaction(function () {
            $balance = Balance::query()
                ->where('user_id', Auth::id())
                ->where('network', $this->networkKey())
                ->first();

            $grossAmount = $balance?->amount ?? 0;

            Withdrawal::create([
                'user_id' => Auth::id(),
                'network' => $this->networkKey(),
                'gross_amount' => $grossAmount,
                'network_fee' => null,
                'amount_sent' => null,
                'destination_address' => $this->withdrawalAddress()?->address ?? '',
                'mode' => $this->mode(),
                'status' => 'pending',
            ]);

            $balance?->update(['amount' => 0]);
        });

        $this->showRequestModal = false;
        $this->successMessage = 'Withdrawal requested. Your full balance has been reserved for processing.';
    }

    private function authorizeRequest(): void
    {
        if (! $this->eligible() || $this->pendingWithdrawal() !== null) {
            abort(403);
        }
    }

    public function confirmCancel(): void
    {
        $this->showCancelModal = true;
    }

    public function cancelWithdrawal(): void
    {
        $withdrawal = $this->pendingWithdrawal();

        if ($withdrawal === null) {
            $this->showCancelModal = false;

            return;
        }

        DB::transaction(function () use ($withdrawal) {
            $balance = Balance::query()
                ->where('user_id', Auth::id())
                ->where('network', $this->networkKey())
                ->first();

            $balance?->update(['amount' => $withdrawal->gross_amount]);
            $withdrawal->update(['status' => 'cancelled']);
        });

        $this->showCancelModal = false;
        $this->successMessage = 'Withdrawal cancelled. The reserved balance has been returned to your available balance.';
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
        $this->successMessage = null;
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.withdraw');
    }
}
