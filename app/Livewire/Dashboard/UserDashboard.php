<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Balance;
use App\Models\Deposit;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Dashboard'])]
class UserDashboard extends Component
{
    use WithPagination;

    /**
     * Network metadata keyed by the DB `network` value.
     *
     * `slug` uses dashes (matches asset file names and filter values);
     * the DB column uses underscores.
     */
    private const NETWORKS = [
        'bitcoin' => ['slug' => 'bitcoin', 'label' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8],
        'usdt_trc20' => ['slug' => 'usdt-trc20', 'label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'decimals' => 2],
        'usdt_erc20' => ['slug' => 'usdt-erc20', 'label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'decimals' => 2],
    ];

    public string $uiState = 'normal';

    public string $networkFilter = 'all';

    public string $period = '30';

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    private static function slugFor(string $dbNetwork): string
    {
        return self::NETWORKS[$dbNetwork]['slug'] ?? str_replace('_', '-', $dbNetwork);
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

        return collect(self::NETWORKS)->map(function (array $meta, string $dbNetwork) use ($valuations, $balances) {
            $amount = (float) ($balances->get($dbNetwork)?->amount ?? 0);
            $rate = (float) ($valuations->get($dbNetwork)?->conversion_value ?? 0);
            $usdValue = round($amount * $rate, 2);

            return [
                'networkSlug' => $meta['slug'],
                'networkLabel' => $meta['label'],
                'symbol' => $meta['symbol'],
                'amount' => number_format($amount, $meta['decimals'], '.', ''),
                'usdValue' => number_format($usdValue, 2, '.', ''),
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
        ])->all();
    }

    #[Computed]
    public function lastUpdated(): string
    {
        $latest = UsdValuation::query()->max('updated_at');

        return $latest ? Carbon::parse($latest)->toIso8601String() : now()->toIso8601String();
    }

    #[Computed]
    public function recentActivity(): array
    {
        $deposits = Deposit::query()
            ->with('customer')
            ->orderByDesc('detected_at')
            ->limit(20)
            ->get()
            ->map(function (Deposit $deposit) {
                $meta = self::NETWORKS[$deposit->network] ?? ['slug' => str_replace('_', '-', $deposit->network), 'label' => $deposit->network, 'decimals' => 8];
                $amount = $deposit->credited_amount ?? $deposit->gross_amount;
                $timestamp = $deposit->detected_at ?? $deposit->created_at;

                return [
                    'type' => 'deposit',
                    'networkSlug' => $meta['slug'],
                    'networkLabel' => $meta['label'],
                    'customerRef' => $deposit->customer?->customer_reference,
                    'txHash' => $deposit->tx_hash,
                    'amount' => number_format((float) $amount, $meta['decimals'], '.', ''),
                    'status' => $deposit->status,
                    'timestamp' => $timestamp->toIso8601String(),
                ];
            });

        $withdrawals = Withdrawal::query()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function (Withdrawal $withdrawal) {
                $meta = self::NETWORKS[$withdrawal->network] ?? ['slug' => str_replace('_', '-', $withdrawal->network), 'label' => $withdrawal->network, 'decimals' => 8];
                $amount = $withdrawal->amount_sent ?? $withdrawal->gross_amount;

                return [
                    'type' => 'withdrawal',
                    'networkSlug' => $meta['slug'],
                    'networkLabel' => $meta['label'],
                    'customerRef' => null,
                    'txHash' => $withdrawal->tx_hash,
                    'amount' => number_format((float) $amount, $meta['decimals'], '.', ''),
                    'status' => $withdrawal->status,
                    'timestamp' => $withdrawal->created_at->toIso8601String(),
                ];
            });

        return $deposits->merge($withdrawals)
            ->sortByDesc(fn (array $item) => strtotime($item['timestamp']))
            ->take(20)
            ->values()
            ->all();
    }

    #[Computed]
    public function filteredActivity(): array
    {
        if ($this->uiState === 'error') {
            return [];
        }

        $activity = $this->recentActivity;
        $since = now()->subDays((int) $this->period);

        return array_values(array_filter($activity, function (array $item) use ($since) {
            $matchesNetwork = $this->networkFilter === 'all' || $item['networkSlug'] === $this->networkFilter;
            $matchesDate = strtotime($item['timestamp']) >= $since->timestamp;

            return $matchesNetwork && $matchesDate;
        }));
    }

    #[Computed]
    public function paginatedActivity(): LengthAwarePaginator
    {
        $activity = $this->filteredActivity;
        $perPage = 10;
        $page = $this->getPage();

        return new LengthAwarePaginator(
            array_slice($activity, ($page - 1) * $perPage, $perPage),
            count($activity),
            $perPage,
            $page,
            ['path' => request()->url()]
        );
    }

    public function updatedNetworkFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
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
