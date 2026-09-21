<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Balance;
use App\Models\UsdValuation;
use App\Support\Network;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Dashboard'])]
class UserDashboard extends Component
{
    public string $uiState = 'normal';

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

        return "Couldn't load dashboard data. The request to the balance service failed.";
    }

    #[Computed]
    public function balances(): array
    {
        $valuations = UsdValuation::query()->get()->keyBy('network');
        $balances = Balance::query()->get()->keyBy('network');

        return collect(Network::presentAll(enabledOnly: true))->map(function (array $meta, string $dbNetwork) use ($valuations, $balances) {
            $amount = (float) ($balances->get($dbNetwork)?->amount ?? 0);
            $rate = (float) ($valuations->get($dbNetwork)?->conversion_value ?? 0);
            $usdValue = round($amount * $rate, 2);

            return [
                'networkSlug' => $meta['slug'],
                'networkLabel' => $meta['label'],
                'symbol' => $meta['symbol'],
                'icon' => $meta['icon'],
                'badge' => $meta['badge'],
                'amount' => number_format($amount, $meta['decimals'], '.', ''),
                'usdValue' => number_format($usdValue, 2),
            ];
        })->values()->all();
    }

    #[Computed]
    public function stats(): array
    {
        $balances = $this->balances;

        return collect($balances)->map(fn (array $balance) => [
            'label' => $balance['networkLabel'],
            'value' => '$'.$balance['usdValue'],
            'amount' => $balance['amount'].' '.$balance['symbol'],
            'network' => $balance['networkSlug'],
            'icon' => $balance['icon'],
            'badge' => $balance['badge'],
            'zero' => (float) $balance['amount'] === 0.0,
        ])->all();
    }

    #[Computed]
    public function lastUpdated(): string
    {
        $latest = UsdValuation::query()->max('updated_at');

        return $latest ? Carbon::parse($latest)->toIso8601String() : now()->toIso8601String();
    }

    public function retry(): void
    {
        $this->uiState = 'normal';
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.user-dashboard');
    }
}
