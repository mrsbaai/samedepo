<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Actions\CancelWithdrawal;
use App\Models\Withdrawal;
use App\Support\Network;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Withdrawals'])]
class Withdrawals extends Component
{
    use WithPagination;

    private const STATUS_COLORS = [
        'pending' => 'amber',
        'approved' => 'blue',
        'sent' => 'green',
        'denied' => 'red',
        'cancelled' => 'zinc',
        'failed' => 'red',
    ];

    public string $uiState = 'normal';

    public ?int $cancellingId = null;

    public bool $showCancelModal = false;

    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load withdrawals. Please try again.";
    }

    #[Computed]
    public function withdrawals(): LengthAwarePaginator
    {
        if ($this->uiState === 'error') {
            return new LengthAwarePaginator([], 0, 10, 1, ['path' => request()->url()]);
        }

        $paginator = Withdrawal::query()
            ->latest('created_at')
            ->paginate(10);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Withdrawal $w): array => $this->present($w))
        );

        return $paginator;
    }

    private function present(Withdrawal $w): array
    {
        $meta = Network::exists($w->network)
            ? Network::present($w->network)
            : ['label' => $w->network, 'symbol' => '', 'decimals' => 8, 'icon' => null, 'badge' => null];

        $format = fn (?string $amount): ?string => $amount === null
            ? null
            : number_format((float) $amount, $meta['decimals'], '.', '');

        return [
            'id' => $w->id,
            'created_at' => $w->created_at,
            'networkLabel' => $meta['label'],
            'symbol' => $meta['symbol'],
            'icon' => $meta['icon'],
            'badge' => $meta['badge'],
            'amount' => $format((string) $w->gross_amount),
            'networkFee' => $format($w->network_fee === null ? null : (string) $w->network_fee),
            'consolidationFee' => $w->consolidation_fee !== null && bccomp((string) $w->consolidation_fee, '0', 8) > 0
                ? $format((string) $w->consolidation_fee)
                : null,
            'sent' => $format($w->amount_sent === null ? null : (string) $w->amount_sent),
            'status' => $w->status,
            'statusLabel' => ucfirst($w->status),
            'statusColor' => self::STATUS_COLORS[$w->status] ?? 'zinc',
            'txHash' => $w->tx_hash,
            'canCancel' => $w->status === 'pending',
        ];
    }

    public function confirmCancel(int $id): void
    {
        $this->cancellingId = $id;
        $this->showCancelModal = true;
    }

    public function cancelWithdrawal(CancelWithdrawal $cancelWithdrawal): void
    {
        $withdrawal = Withdrawal::query()->find($this->cancellingId);

        if ($withdrawal !== null && $cancelWithdrawal($withdrawal)) {
            $this->successMessage = 'Withdrawal cancelled. The reserved balance has been returned to your available balance.';
        }

        $this->showCancelModal = false;
        $this->cancellingId = null;
    }

    public function retry(): void
    {
        $this->uiState = 'normal';
        $this->successMessage = null;
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.withdrawals');
    }
}
