<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Balance;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
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

    private const NETWORKS = [
        'bitcoin' => ['label' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8, 'slug' => 'bitcoin'],
        'usdt_trc20' => ['label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'decimals' => 2, 'slug' => 'usdt-trc20'],
        'usdt_erc20' => ['label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'decimals' => 2, 'slug' => 'usdt-erc20'],
    ];

    private const ESTIMATED_FEES = [
        'bitcoin' => 0.0002,
        'usdt_trc20' => 1.0,
        'usdt_erc20' => 5.0,
    ];

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
        return self::NETWORKS[$this->withdrawalRecord->network] ?? ['label' => $this->withdrawalRecord->network, 'symbol' => '', 'decimals' => 8, 'slug' => $this->withdrawalRecord->network];
    }

    public function estimatedFee(): float
    {
        return self::ESTIMATED_FEES[$this->withdrawalRecord->network] ?? 0;
    }

    public function estimatedReceive(): float
    {
        return max(0, (float) $this->withdrawalRecord->gross_amount - $this->estimatedFee());
    }

    public function formattedAmount(float $amount): string
    {
        return number_format($amount, $this->networkMeta()['decimals']);
    }

    public function usdValue(float $cryptoAmount): string
    {
        $valuation = UsdValuation::query()
            ->where('network', $this->withdrawalRecord->network)
            ->first();

        if ($valuation === null) {
            return '0.00';
        }

        return number_format($cryptoAmount * (float) $valuation->conversion_value, 2);
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

        $fee = $this->estimatedFee();
        $sent = max(0, (float) $this->withdrawalRecord->gross_amount - $fee);

        $this->withdrawalRecord->update([
            'status' => 'approved',
            'network_fee' => $fee,
            'amount_sent' => $sent,
            'decided_at' => now(),
            'decided_by' => Auth::id(),
            'sent_at' => now(),
            'tx_hash' => 'pending-'.uniqid(),
        ]);

        $this->showApproveModal = false;
        $this->successMessage = 'Withdrawal approved. The funds have been sent to the destination address.';
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
