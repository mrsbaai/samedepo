<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Deposit;
use App\Models\SupportTicket;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Security\Models\SecurityBlock;
use App\Security\Models\ThreatEvent;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Admin Dashboard'])]
class AdminDashboard extends Component
{
    public function render(): mixed
    {
        return view('livewire.dashboard.admin-dashboard', [
            'tickets' => $this->tickets(),
            'platformStatus' => $this->platformStatus(),
            'securitySummary' => $this->securitySummary(),
        ]);
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
     * @param array<string, float> $conversions
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
