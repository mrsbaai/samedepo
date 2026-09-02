<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Deposit;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\SupportTicket;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Security\Models\SecurityBlock;
use App\Security\Models\ThreatEvent;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\TreasuryProfitCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Dashboard'])]
class AdminDashboard extends Component
{
    private const NETWORKS = [
        'bitcoin' => ['label' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8],
        'usdt_trc20' => ['label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'decimals' => 2],
        'usdt_erc20' => ['label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'decimals' => 2],
    ];

    public function render(): mixed
    {
        return view('livewire.dashboard.admin-dashboard', [
            'tickets' => $this->tickets(),
            'platformStatus' => $this->platformStatus(),
            'treasury' => $this->treasury(),
            'networkMeta' => self::NETWORKS,
            'securitySummary' => $this->securitySummary(),
        ]);
    }

    public function refreshTreasuryData(GasTreasuryService $gasTreasury): void
    {
        $gasTreasury->refreshStaleTreasuryWallets();
    }

    public function closeTicket(int $id): void
    {
        SupportTicket::findOrFail($id)->update(['status' => SupportTicket::STATUS_CLOSED]);
    }

    /** @return Collection<int, SupportTicket> */
    private function tickets(): Collection
    {
        return SupportTicket::query()
            ->where('status', SupportTicket::STATUS_OPEN)
            ->with(['user', 'latestMessage.user'])
            ->get()
            ->sortByDesc(fn (SupportTicket $ticket) => [
                $ticket->latestMessage?->user?->is_admin ? 0 : 1,
                $ticket->last_message_at,
            ])
            ->values();
    }

    /** @return array<string, mixed> */
    private function treasury(): array
    {
        $summary = app(TreasuryProfitCalculator::class)->summary();
        $settings = PlatformSettings::instance();
        $addresses = [
            'bitcoin' => $settings->profit_address_bitcoin,
            'usdt_trc20' => $settings->profit_address_usdt_trc20,
            'usdt_erc20' => $settings->profit_address_usdt_erc20,
        ];

        $gas = ['bitcoin' => 'not_applicable'];
        foreach (['usdt_trc20', 'usdt_erc20'] as $network) {
            $wallet = TreasuryWallet::query()->where('network', $network)->first();
            $policy = GasPolicy::query()->where('network', $network)->first();
            $gas[$network] = match (true) {
                $policy?->manual_paused === true => 'paused',
                $wallet === null || $wallet->native_balance === null => 'unknown',
                $policy !== null && bccomp((string) $wallet->native_balance, (string) $policy->reserve_threshold, 8) < 0 => 'low',
                default => 'ready',
            };
        }

        $since = now()->subDay();
        $failures24h = TreasurySweep::query()->where('status', 'failed')->where('updated_at', '>=', $since)->count()
            + TreasuryPayout::query()->where('status', 'failed')->where('updated_at', '>=', $since)->count()
            + GasTopup::query()->where('status', 'failed')->where('updated_at', '>=', $since)->count();

        $unsweptUsd = '0.00000000';
        $unsweptAddresses = 0;
        foreach ($summary['networks'] as $network => $n) {
            $unsweptUsd = bcadd($unsweptUsd, $n['unswept_usd'], 8);
            $unsweptAddresses += Deposit::query()->withoutGlobalScope('owner')->where('network', $network)->where('status', 'credited')->whereNull('swept_at')->distinct()->count('deposit_address_id');
        }

        $bestNetwork = null;
        $best = '0';
        foreach ($summary['networks'] as $network => $n) {
            if ($addresses[$network] && bccomp($n['withdrawable_usd'], $best, 8) > 0) {
                $best = $n['withdrawable_usd'];
                $bestNetwork = $network;
            }
        }

        $missingAddress = collect($summary['networks'])->contains(fn ($n, $network) => ! $addresses[$network] && bccomp($n['withdrawable'], '0', 8) > 0);
        $oldestRefresh = TreasuryWallet::query()->min('refreshed_at');
        $stale = $oldestRefresh === null || Carbon::parse($oldestRefresh)->lt(now()->subMinutes(2));

        $status = match (true) {
            $summary['has_deficit'] => 'deficit',
            $stale || in_array('low', $gas, true) || $missingAddress || $failures24h > 0 => 'attention',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'networks' => $summary['networks'],
            'totalWithdrawableUsd' => $summary['total_withdrawable_usd'],
            'totalEquityUsd' => $summary['total_equity_usd'],
            'unsweptUsd' => $unsweptUsd,
            'unsweptAddresses' => $unsweptAddresses,
            'gas' => $gas,
            'failures24h' => $failures24h,
            'bestNetwork' => $bestNetwork,
            'anyAddressMissing' => in_array(null, $addresses, true) || in_array('', $addresses, true),
            'stale' => $stale,
            'oldestRefresh' => $oldestRefresh,
        ];
    }

    /** @return array<string, mixed> */
    private function platformStatus(): array
    {
        $conversions = $this->latestConversions();
        $deposit24h = $this->depositStats(now()->subDay(), $conversions);
        $deposit7d = $this->depositStats(now()->subDays(7), $conversions);
        $pendingWithdrawals = Withdrawal::query()->withoutGlobalScope('owner')->where('status', 'pending')->get();
        $pendingUsd = $pendingWithdrawals->sum(fn (Withdrawal $w) => (float) $w->gross_amount * ($conversions[$w->network] ?? 0));

        return [
            'ownerCount' => User::query()->where('role', 'owner')->count(),
            'newOwnersToday' => User::query()->where('role', 'owner')->whereDate('created_at', today())->count(),
            'deposits24h' => $deposit24h,
            'deposits7d' => $deposit7d,
            'pendingWithdrawals' => [
                'count' => $pendingWithdrawals->count(),
                'usdValue' => $pendingUsd,
            ],
        ];
    }

    /** @return array<string, float> */
    private function latestConversions(): array
    {
        return UsdValuation::query()
            ->orderByDesc('id')
            ->get()
            ->unique('network')
            ->mapWithKeys(fn (UsdValuation $valuation) => [
                $valuation->network => (float) $valuation->conversion_value,
            ])
            ->all();
    }

    /**
     * @param  array<string, float>  $conversions
     * @return array<string, mixed>
     */
    private function depositStats(\DateTimeInterface $since, array $conversions): array
    {
        $deposits = Deposit::query()
            ->withoutGlobalScope('owner')
            ->where('status', 'credited')
            ->where('credited_at', '>=', $since)
            ->get(['network', 'gross_amount']);

        $usdValue = $deposits->sum(fn (Deposit $deposit) => (float) $deposit->gross_amount * ($conversions[$deposit->network] ?? 0));

        return [
            'count' => $deposits->count(),
            'usdValue' => $usdValue,
        ];
    }

    /** @return array<string, mixed> */
    private function securitySummary(): array
    {
        $oneHourAgo = now()->subHour();
        $oneDayAgo = now()->subDay();

        $events1h = ThreatEvent::query()->where('created_at', '>=', $oneHourAgo)->count();
        $events24h = ThreatEvent::query()->where('created_at', '>=', $oneDayAgo)->count();
        $ips1h = $this->distinctIpCount($oneHourAgo);
        $ips24h = $this->distinctIpCount($oneDayAgo);
        $critical1h = ThreatEvent::query()->where('created_at', '>=', $oneHourAgo)->where('severity', '>=', 9)->count();

        $status = match (true) {
            $events1h >= 5 || $events24h >= 20 || $critical1h >= 1 => 'active',
            $events1h >= 2 || $events24h >= 10 => 'elevated',
            default => 'calm',
        };

        return [
            'events1h' => $events1h,
            'events24h' => $events24h,
            'ips1h' => $ips1h,
            'ips24h' => $ips24h,
            'blockedIps' => SecurityBlock::query()->where('type', SecurityBlock::TYPE_IP)->count(),
            'blockedDevices' => SecurityBlock::query()->where('type', SecurityBlock::TYPE_DEVICE)->count(),
            'status' => $status,
        ];
    }

    private function distinctIpCount(\DateTimeInterface $since): int
    {
        $result = ThreatEvent::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(DISTINCT ip_address) as count')
            ->first();

        return (int) ($result?->count ?? 0);
    }
}
