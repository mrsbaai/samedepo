<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\UsdValuation;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Admin Withdrawal Queue'])]
class WithdrawalQueue extends Component
{
    use WithPagination;

    public string $uiState = 'normal';

    private const NETWORKS = [
        'bitcoin' => ['label' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8, 'slug' => 'bitcoin'],
        'usdt_trc20' => ['label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'decimals' => 2, 'slug' => 'usdt-trc20'],
        'usdt_erc20' => ['label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'decimals' => 2, 'slug' => 'usdt-erc20'],
    ];

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
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc');
    }

    public function networkMeta(string $networkKey): array
    {
        return self::NETWORKS[$networkKey] ?? ['label' => $networkKey, 'symbol' => '', 'decimals' => 8, 'slug' => $networkKey];
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
