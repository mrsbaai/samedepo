<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Support\Network;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Admin Withdrawal Queue'])]
class WithdrawalQueue extends Component
{
    use WithPagination;

    public string $uiState = 'normal';

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    #[Computed]
    public function withdrawalsQuery()
    {
        return Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->with('user')
            ->whereIn('status', ['pending', 'approved'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'asc');
    }

    public function networkMeta(string $networkKey): array
    {
        return Network::exists($networkKey) ? Network::present($networkKey) : ['label' => $networkKey, 'symbol' => '', 'decimals' => 8, 'slug' => $networkKey];
    }

    public function formattedAmount(float $amount, int $decimals): string
    {
        return number_format($amount, $decimals);
    }

    public function usdValue(float $cryptoAmount, string $networkKey): string
    {
        $valuation = UsdValuation::query()
            ->where('network', $networkKey)
            ->first();

        if ($valuation === null) {
            return '0.00';
        }

        return number_format($cryptoAmount * (float) $valuation->conversion_value, 2);
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    public function render(): mixed
    {
        return view('livewire.admin.withdrawal-queue', [
            'withdrawals' => $this->uiState === 'normal' ? $this->withdrawalsQuery->paginate(10) : collect(),
        ]);
    }
}
