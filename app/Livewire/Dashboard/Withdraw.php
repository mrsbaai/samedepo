<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Balance;
use App\Models\EnergyRental;
use App\Models\GasExpense;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Models\WithdrawalAddress;
use App\Services\Blockchain\WithdrawalQuote;
use App\Support\Network;
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

    public string $network = '';

    public bool $showRequestModal = false;

    public bool $showCancelModal = false;

    public ?string $successMessage = null;

    public function mount(?string $network = null): void
    {
        $this->network = $network
            ?? request()->query('network')
            ?? Network::present(Network::enabledKeys()[0])['slug'];

        if (! Network::exists($this->networkKey()) || ! Network::get($this->networkKey())['enabled']) {
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
        return Network::present($this->networkKey());
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

        return (float) bcmul((string) $this->balanceModel()->amount, (string) $valuation->conversion_value, 8);
    }

    #[Computed]
    public function minimumUsd(): float
    {
        return (float) PlatformSettings::networkSetting($this->networkKey())->withdrawal_min_usd;
    }

    #[Computed]
    public function eligible(): bool
    {
        return $this->usdValue() >= $this->minimumUsd();
    }

    #[Computed]
    public function feeEstimate(): ?array
    {
        try {
            $quote = app(WithdrawalQuote::class)->quote(
                (int) Auth::id(),
                $this->networkKey(),
                (string) $this->balanceModel()->amount,
                destination: $this->withdrawalAddress()?->address,
            );

            if ($quote === null) {
                return null;
            }

            return app(WithdrawalQuote::class)->display($quote);
        } catch (\Throwable) {
            return null;
        }
    }

    public function usdFor(string $amount): string
    {
        $valuation = UsdValuation::query()->where('network', $this->networkKey())->value('conversion_value') ?? 0;

        return number_format((float) bcadd(bcmul($amount, (string) $valuation, 8), '0', 2), 2);
    }

    #[Computed]
    public function withdrawalAddress(): ?WithdrawalAddress
    {
        return WithdrawalAddress::query()->where('network', $this->networkKey())->first();
    }

    #[Computed]
    public function mode(): string
    {
        $mode = Auth::user()?->withdrawal_mode ?? PlatformSettings::instance()->default_withdrawal_mode;

        return $mode === 'instant' ? 'instant' : 'approval';
    }

    #[Computed]
    public function pendingWithdrawal(): ?Withdrawal
    {
        return Withdrawal::query()
            ->where('network', $this->networkKey())
            ->whereIn('status', ['pending', 'approved'])
            ->latest()
            ->first();
    }

    #[Computed]
    public function sentWithdrawal(): ?Withdrawal
    {
        if ($this->pendingWithdrawal() !== null) {
            return null;
        }

        return Withdrawal::query()
            ->where('network', $this->networkKey())
            ->where('status', 'sent')
            ->latest()
            ->first();
    }

    #[Computed]
    public function reconciliation(): ?array
    {
        $withdrawal = $this->pendingWithdrawal() ?? $this->sentWithdrawal();

        if ($withdrawal === null || $withdrawal->network_fee === null) {
            return null;
        }

        $expense = GasExpense::query()
            ->where('expensable_type', Withdrawal::class)
            ->where('expensable_id', $withdrawal->id)
            ->first();

        if ($expense === null) {
            return null;
        }

        $rentalNative = EnergyRental::query()
            ->where('purposable_type', (new Withdrawal)->getMorphClass())
            ->where('purposable_id', $withdrawal->id)
            ->whereIn('status', ['filled', 'expired'])
            ->sum('cost_native');

        $adjustment = LedgerEntry::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('reason', 'network_fee_adjustment')
            ->latest('id')
            ->value('amount');

        return [
            'actual_native' => bcadd((string) $expense->amount, (string) $rentalNative, 8),
            'native_symbol' => Network::nativeSymbol($withdrawal->network),
            'adjustment' => $adjustment === null ? null : (string) $adjustment,
        ];
    }

    public function formattedAmount(string $amount): string
    {
        $decimals = $this->networkMeta()['decimals'];

        return number_format((float) bcadd($amount, '0', $decimals), $decimals);
    }

    public function formattedUsd(): string
    {
        return number_format((float) bcadd((string) $this->usdValue(), '0', 2), 2);
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

        if ($withdrawal === null || $withdrawal->status !== 'pending') {
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
