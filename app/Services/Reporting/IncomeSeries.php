<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Deposit;
use App\Support\Network;
use Illuminate\Support\Carbon;

final class IncomeSeries
{
    /**
     * Income time series from credited deposits' stored usd_value.
     *
     * @param  array<int, string>  $networks
     * @return array{bucket: string, points: array<int, array<string, mixed>>, totals: array<string, float>, share: array<int, array{label: string, network: string, value: float}>}
     */
    public static function build(?int $userId, Carbon $from, Carbon $to, array $networks): array
    {
        $bucket = self::bucketFor($from, $to);

        $points = [];
        $totals = array_fill_keys($networks, 0.0);

        foreach (self::buckets($from, $to, $bucket) as $label) {
            $points[$label] = array_merge(['t' => $label, 'total' => 0.0], $totals);
        }

        Deposit::query()
            ->withoutGlobalScope('owner')
            ->where('status', 'credited')
            ->whereBetween('credited_at', [$from, $to])
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->whereIn('network', $networks)
            ->get(['network', 'usd_value', 'credited_at'])
            ->each(function (Deposit $deposit) use (&$points, &$totals, $bucket): void {
                if ($deposit->credited_at === null) {
                    return;
                }

                $label = self::label($deposit->credited_at, $bucket);

                if (! isset($points[$label])) {
                    return;
                }

                $value = (float) ($deposit->usd_value ?? 0);
                $points[$label]['total'] += $value;
                $points[$label][$deposit->network] += $value;
                $totals[$deposit->network] += $value;
            });

        $grand = array_sum($totals);
        $share = [];

        if ($grand > 0) {
            foreach ($networks as $network) {
                if ($totals[$network] <= 0) {
                    continue;
                }

                $share[] = [
                    'label' => Network::exists($network) ? Network::label($network) : $network,
                    'network' => $network,
                    'value' => round($totals[$network] / $grand * 100, 1),
                ];
            }

            usort($share, fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        }

        return [
            'bucket' => $bucket,
            'points' => array_values($points),
            'totals' => $totals,
            'share' => $share,
        ];
    }

    private static function bucketFor(Carbon $from, Carbon $to): string
    {
        $days = $from->floatDiffInDays($to);

        return $days <= 1 ? 'hour' : ($days <= 60 ? 'day' : 'month');
    }

    /**
     * @return array<int, string>
     */
    private static function buckets(Carbon $from, Carbon $to, string $bucket): array
    {
        $cursor = $from->copy()->{$bucket === 'hour' ? 'startOfHour' : ($bucket === 'day' ? 'startOfDay' : 'startOfMonth')}();
        $step = $bucket === 'hour' ? 'addHour' : ($bucket === 'day' ? 'addDay' : 'addMonth');

        $labels = [];

        while ($cursor <= $to) {
            $labels[] = self::label($cursor, $bucket);
            $cursor->{$step}();
        }

        return $labels;
    }

    private static function label(Carbon $at, string $bucket): string
    {
        return $at->format(match ($bucket) {
            'hour' => 'Y-m-d\TH:00:00',
            'day' => 'Y-m-d',
            'month' => 'Y-m',
        });
    }
}
