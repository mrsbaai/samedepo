<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Balance;
use App\Models\Deposit;
use App\Models\GasPolicy;
use App\Models\GasTopup;
use App\Models\PlatformSettings;
use App\Models\SupportTicket;
use App\Models\TreasuryPayout;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Models\Withdrawal;
use App\Security\Models\SecurityBlock;
use App\Security\Models\ThreatEvent;
use App\Services\Blockchain\Energy\TronSaveClient;
use App\Services\Blockchain\GasTreasuryService;
use App\Services\Blockchain\TreasuryProfitCalculator;
use App\Support\DepositRow;
use App\Support\Network;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Dashboard'])]
class AdminDashboard extends Component
{
    public function render(): mixed
    {
        $blocked = $this->blockedQueue();

        return view('livewire.dashboard.admin-dashboard', [
            'tickets' => $this->tickets(),
            'pendingWithdrawals' => $this->pendingWithdrawals(),
            'blocked' => $blocked,
            'treasury' => $this->treasury($blocked['items']->isNotEmpty()),
            'networkMeta' => DepositRow::networks(),
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

    public function approve(int $id): void
    {
        $withdrawal = Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->where('status', 'pending')
            ->where('mode', '!=', 'instant')
            ->find($id);

        if ($withdrawal === null) {
            return;
        }

        $withdrawal->update([
            'status' => 'approved',
            'decided_at' => now(),
            'decided_by' => Auth::id(),
        ]);
    }

    public function deny(int $id): void
    {
        $withdrawal = Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->where('status', 'pending')
            ->where('mode', '!=', 'instant')
            ->find($id);

        if ($withdrawal === null) {
            return;
        }

        DB::transaction(function () use ($withdrawal): void {
            $balance = Balance::query()
                ->withoutGlobalScope('owner')
                ->where('user_id', $withdrawal->user_id)
                ->where('network', $withdrawal->network)
                ->first();

            Balance::query()->withoutGlobalScope('owner')->updateOrCreate(
                ['user_id' => $withdrawal->user_id, 'network' => $withdrawal->network],
                ['amount' => (float) ($balance?->amount ?? 0) + (float) $withdrawal->gross_amount]
            );

            $withdrawal->update([
                'status' => 'denied',
                'decided_at' => now(),
                'decided_by' => Auth::id(),
            ]);
        });
    }

    public function formattedAmount(float $amount, int $decimals): string
    {
        return number_format($amount, $decimals);
    }

    public function usdValue(float $cryptoAmount, string $networkKey): string
    {
        $valuation = UsdValuation::query()->where('network', $networkKey)->first();

        if ($valuation === null) {
            return '0.00';
        }

        return number_format($cryptoAmount * (float) $valuation->conversion_value, 2);
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
    private function treasury(bool $blocked = false): array
    {
        $summary = app(TreasuryProfitCalculator::class)->summary();
        $addresses = [];
        foreach (Network::enabledKeys() as $key) {
            $addresses[$key] = PlatformSettings::networkSetting($key)->profit_address;
        }

        $gas = [];
        foreach (Network::enabledKeys() as $network) {
            if (! Network::isToken($network)) {
                $gas[$network] = 'not_applicable';

                continue;
            }

            $wallet = TreasuryWallet::query()->where('network', $network)->first();
            $policy = GasPolicy::query()->where('network', Network::nativeKey($network))->first();
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
            + GasTopup::query()->where('status', 'failed')->where('kind', 'topup')->where('updated_at', '>=', $since)->count();

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
            $blocked || $stale || in_array('low', $gas, true) || $missingAddress || $failures24h > 0 => 'attention',
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

    /**
     * Everything waiting on operator action: in-flight withdrawals, sweeps,
     * and payouts carrying a recorded block reason, plus per-network top-up
     * callouts telling the admin exactly where to send funds.
     *
     * @return array{items: Collection<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}
     */
    private function blockedQueue(): array
    {
        $items = collect();

        Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->with('user')
            ->whereNotNull('last_error')
            ->where(fn ($query) => $query->where('status', 'approved')
                ->orWhere(fn ($sub) => $sub->where('status', 'pending')->where('mode', 'instant')))
            ->orderBy('updated_at')
            ->get()
            ->each(fn (Withdrawal $w) => $items->push([
                'type' => 'Withdrawal',
                'id' => $w->id,
                'ref' => $w->user?->email ?? 'Unknown owner',
                'network' => $w->network,
                'amount' => (float) $w->gross_amount,
                'reason' => (string) $w->last_error,
                'reasonLabel' => $w->lastErrorLabel() ?? '',
                'since' => $w->updated_at,
            ]));

        TreasurySweep::query()
            ->whereNull('tx_hash')
            ->whereNotNull('error_message')
            ->whereNotIn('status', ['confirmed', 'failed'])
            ->orderBy('updated_at')
            ->get()
            ->each(fn (TreasurySweep $s) => $items->push([
                'type' => 'Sweep',
                'id' => $s->id,
                'ref' => '#'.$s->id,
                'network' => $s->network,
                'amount' => (float) $s->amount,
                'reason' => (string) $s->error_message,
                'reasonLabel' => $this->blockReasonLabel($s->error_message),
                'since' => $s->updated_at,
            ]));

        TreasuryPayout::query()
            ->where('status', 'pending')
            ->whereNotNull('error_message')
            ->orderBy('updated_at')
            ->get()
            ->each(fn (TreasuryPayout $p) => $items->push([
                'type' => 'Payout',
                'id' => $p->id,
                'ref' => '#'.$p->id,
                'network' => $p->network,
                'amount' => (float) $p->amount,
                'reason' => (string) $p->error_message,
                'reasonLabel' => $this->blockReasonLabel($p->error_message),
                'since' => $p->updated_at,
            ]));

        return ['items' => $items, 'actions' => $this->blockedActions($items)];
    }

    private function blockReasonLabel(?string $reason): string
    {
        if ($reason === null) {
            return '';
        }

        $code = explode(':', $reason, 2)[0];

        return Withdrawal::ERROR_LABELS[$code] ?? 'Needs operator attention';
    }

    /**
     * Per-network top-up callouts derived from the blocked reasons: TRX to the
     * TronSave float on TRON rent-mode networks, native gas to the treasury
     * wallet on EVM/burn-mode networks.
     *
     * @return array<int, array{amount: string, symbol: string, address: ?string, target: string}>
     */
    private function blockedActions(Collection $items): array
    {
        $actions = [];

        foreach ($items->pluck('network')->unique() as $network) {
            if (! Network::isToken($network)) {
                continue;
            }

            $wallet = TreasuryWallet::query()->where('network', $network)->first();
            $policy = GasPolicy::query()->where('network', Network::nativeKey($network))->first();

            if ($wallet === null || $policy === null) {
                continue;
            }

            if (Network::family($network) === 'tron' && $policy->energy_mode === 'rent') {
                $networkItems = $items->where('network', $network);
                $floatLow = $networkItems->contains(fn ($item) => str_starts_with((string) $item['reason'], 'energy_float_low'))
                    || ($wallet->rental_balance !== null && bccomp((string) $wallet->rental_balance, (string) $policy->rent_float_alert_trx, 8) < 0);

                if ($floatLow) {
                    $needed = '0';
                    foreach ($networkItems as $item) {
                        if (preg_match('/need ([\d.]+) TRX/', (string) $item['reason'], $matches)) {
                            $needed = bcadd($needed, $matches[1], 8);
                        }
                    }

                    $target = bccomp($needed, (string) $policy->rent_float_alert_trx, 8) > 0
                        ? $needed
                        : (string) $policy->rent_float_alert_trx;
                    $shortfall = bcsub($target, (string) ($wallet->rental_balance ?? '0'), 8);
                    $amount = (string) (int) ceil(max(0, (float) $shortfall));

                    $info = Cache::remember('tronsave-user-info', 300, fn () => app(TronSaveClient::class)->userInfo());

                    $actions[] = [
                        'amount' => $amount,
                        'symbol' => 'TRX',
                        'address' => $info['depositAddress'] ?? null,
                        'target' => 'the TronSave deposit address',
                    ];
                }

                continue;
            }

            $needed = bcsub(
                bcadd((string) $policy->reserve_threshold, (string) $policy->top_up_amount, 8),
                (string) ($wallet->native_balance ?? '0'),
                8,
            );

            if (bccomp($needed, '0', 8) > 0) {
                $actions[] = [
                    'amount' => number_format(ceil(((float) $needed) * 10000) / 10000, 4),
                    'symbol' => Network::nativeSymbol($network),
                    'address' => (string) $wallet->address,
                    'target' => 'the treasury address',
                ];
            }
        }

        return $actions;
    }

    /** @return array<string, mixed> */
    private function pendingWithdrawals(): array
    {
        $conversions = $this->latestConversions();
        $pendingWithdrawals = Withdrawal::query()
            ->withoutGlobalScope('owner')
            ->with('user')
            ->where('status', 'pending')
            ->where('mode', '!=', 'instant')
            ->orderBy('created_at', 'asc')
            ->get();
        $pendingUsd = $pendingWithdrawals->sum(fn (Withdrawal $w) => (float) $w->gross_amount * ($conversions[$w->network] ?? 0));

        return [
            'count' => $pendingWithdrawals->count(),
            'usdValue' => $pendingUsd,
            'items' => $pendingWithdrawals->take(10),
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
