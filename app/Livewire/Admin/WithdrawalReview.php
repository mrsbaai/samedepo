<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Balance;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Services\Blockchain\WithdrawalQuote;
use App\Support\Network;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Withdrawal Review'])]
class WithdrawalReview extends Component
{
    public string $uiState = 'normal';

    public ?Withdrawal $withdrawalRecord = null;

    public bool $showApproveModal = false;

    public bool $showDenyModal = false;

    public ?string $successMessage = null;

    public function mount(int $withdrawal): void
    {
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState !== 'error') {
            $this->loadWithdrawal($withdrawal);
        }
    }

    private function loadWithdrawal(int $withdrawalId): void
    {
        $withdrawal = Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->with('user')
            ->where('status', 'pending')
            ->find($withdrawalId);

        if ($withdrawal === null) {
            $this->uiState = 'not-found';

            return;
        }

        $this->withdrawalRecord = $withdrawal;
    }

    #[Computed]
    public function networkMeta(): array
    {
        return Network::exists($this->withdrawalRecord->network) ? Network::present($this->withdrawalRecord->network) : ['label' => $this->withdrawalRecord->network, 'symbol' => '', 'decimals' => 8, 'slug' => $this->withdrawalRecord->network, 'icon' => null, 'badge' => null];
    }

    /**
     * Same live signer estimate, buffer, and native-to-token conversion the
     * owner's Withdraw page shows and WithdrawalProcessor locks at send time.
     * Shares the owner page's cache key so both screens read the same number.
     */
    #[Computed]
    public function feeEstimate(): ?array
    {
        $withdrawal = $this->withdrawalRecord;

        try {
            $quote = app(WithdrawalQuote::class)->quote(
                (int) $withdrawal->user_id,
                $withdrawal->network,
                (string) $withdrawal->gross_amount,
                $withdrawal,
            );

            if ($quote === null) {
                return null;
            }

            return app(WithdrawalQuote::class)->display($quote);
        } catch (\Throwable) {
            return null;
        }
    }

    public function estimatedFee(): ?string
    {
        $estimate = $this->feeEstimate();

        return $estimate === null ? null : $estimate['total_fee'];
    }

    public function estimatedReceive(): ?string
    {
        $fee = $this->estimatedFee();

        return $fee === null ? null : bcsub((string) $this->withdrawalRecord->gross_amount, $fee, 8);
    }

    public function formattedAmount(string $amount): string
    {
        $decimals = $this->networkMeta()['decimals'];

        return number_format((float) bcadd($amount, '0', $decimals), $decimals);
    }

    public function usdValue(string $cryptoAmount): string
    {
        $valuation = UsdValuation::query()
            ->where('network', $this->withdrawalRecord->network)
            ->first();

        if ($valuation === null) {
            return '0.00';
        }

        return number_format((float) bcadd(bcmul($cryptoAmount, (string) $valuation->conversion_value, 8), '0', 2), 2);
    }

    public function confirmApprove(): void
    {
        $this->showApproveModal = true;
    }

    public function approve(): void
    {
        if ($this->withdrawalRecord === null || $this->withdrawalRecord->status !== 'pending') {
            $this->showApproveModal = false;

            return;
        }

        $this->withdrawalRecord->update([
            'status' => 'approved',
            'decided_at' => now(),
            'decided_by' => Auth::id(),
        ]);

        $this->showApproveModal = false;
        $this->successMessage = 'Withdrawal approved and queued. It will be sent automatically once treasury funds and gas are available.';
    }

    public function confirmDeny(): void
    {
        $this->showDenyModal = true;
    }

    public function deny(): void
    {
        if ($this->withdrawalRecord === null || $this->withdrawalRecord->status !== 'pending') {
            $this->showDenyModal = false;

            return;
        }

        DB::transaction(function () {
            $balance = Balance::query()
                ->withoutGlobalScope('owner')
                ->where('user_id', $this->withdrawalRecord->user_id)
                ->where('network', $this->withdrawalRecord->network)
                ->first();

            Balance::query()
                ->withoutGlobalScope('owner')
                ->updateOrCreate(
                    ['user_id' => $this->withdrawalRecord->user_id, 'network' => $this->withdrawalRecord->network],
                    ['amount' => (float) ($balance?->amount ?? 0) + (float) $this->withdrawalRecord->gross_amount]
                );

            $this->withdrawalRecord->update([
                'status' => 'denied',
                'decided_at' => now(),
                'decided_by' => Auth::id(),
            ]);
        });

        $this->showDenyModal = false;
        $this->successMessage = 'Withdrawal denied. The reserved balance has been returned to the website owner.';
    }

    public function retry(): void
    {
        $this->successMessage = null;
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState === 'error' || $this->withdrawalRecord === null) {
            return;
        }

        $this->loadWithdrawal($this->withdrawalRecord->id);
    }

    public function render(): mixed
    {
        return view('livewire.admin.withdrawal-review');
    }
}
