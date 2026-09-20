<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Balance;
use App\Models\Deposit;
use App\Services\Reporting\IncomeSeries;
use App\Support\Network;
use Flux\DateRange;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class IncomeCharts extends Component
{
    public ?int $ownerId = null;

    public string $range = '7d';

    public DateRange $customRange;

    /** @var array<int, string> */
    public array $networks = [];

    public function mount(?int $ownerId = null): void
    {
        $this->ownerId = $ownerId;
        $this->networks = Network::enabledKeys();
        $this->customRange = new DateRange(now()->subDays(6)->startOfDay(), now());
    }

    #[Computed]
    public function networkOptions(): array
    {
        $present = Network::presentAll(enabledOnly: true);

        if ($this->ownerId === null) {
            return $present;
        }

        $income = Deposit::query()
            ->withoutGlobalScope('owner')
            ->where('status', 'credited')
            ->where('user_id', $this->ownerId)
            ->whereIn('network', array_keys($present))
            ->selectRaw('network, SUM(usd_value) as total')
            ->groupBy('network')
            ->pluck('total', 'network');

        $balances = Balance::query()
            ->withoutGlobalScope('owner')
            ->where('user_id', $this->ownerId)
            ->pluck('amount', 'network');

        return array_filter(
            $present,
            fn (array $meta, string $key): bool => (float) ($income[$key] ?? 0) > 0 || (float) ($balances[$key] ?? 0) > 0,
            ARRAY_FILTER_USE_BOTH
        );
    }

    #[Computed]
    public function series(): array
    {
        [$from, $to] = $this->bounds();

        return IncomeSeries::build($this->ownerId, $from, $to, $this->visibleNetworks());
    }

    #[Computed]
    public function donutSegments(): array
    {
        $meta = $this->networkOptions;

        return array_map(
            fn (array $row): array => $row + ['color' => $meta[$row['network']]['chart_color'] ?? 'zinc-400'],
            $this->series['share'],
        );
    }

    #[Computed]
    public function totalUsd(): string
    {
        return '$'.number_format(array_sum($this->series['totals']), 2);
    }

    #[Computed]
    public function hasIncome(): bool
    {
        return array_sum($this->series['totals']) > 0;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function visibleNetworks(): array
    {
        return array_values(array_intersect($this->networks, Network::enabledKeys()));
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function activeNetworks(): array
    {
        return array_values(array_filter(
            $this->visibleNetworks(),
            fn (string $key): bool => ($this->series['totals'][$key] ?? 0) > 0
        ));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function xAxisFormat(): array
    {
        return match ($this->series['bucket']) {
            'hour' => ['hour' => '2-digit', 'minute' => '2-digit', 'hourCycle' => 'h23'],
            'month' => ['month' => 'short', 'year' => 'numeric'],
            default => ['month' => 'short', 'day' => 'numeric'],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(): array
    {
        return match ($this->range) {
            'today' => [today(), now()],
            '30d' => [today()->subDays(29), now()],
            'custom' => [
                Carbon::parse($this->customRange->start())->startOfDay(),
                Carbon::parse($this->customRange->end())->endOfDay(),
            ],
            default => [today()->subDays(6), now()],
        };
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.income-charts');
    }
}
