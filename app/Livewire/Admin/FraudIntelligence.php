<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Fraud\Models\Device;
use App\Fraud\Models\EntityLink;
use App\Fraud\Models\FraudAlert;
use App\Fraud\Models\FraudLevelPolicy;
use App\Fraud\Models\FraudMetricSetting;
use App\Fraud\Models\UserRisk;
use App\Fraud\RiskCalculator;
use App\Models\User;
use App\Security\Blocklist\DeviceBlocklist;
use App\Security\Blocklist\IpBlocklist;
use App\Security\Models\ThreatEvent;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Fraud Intelligence'])]
class FraudIntelligence extends Component
{
    /** Bound to the detail modal; Livewire sets it to false on close. */
    public $selectedUserId = null;

    public function mount(): void
    {
        // Seed metric settings and level policies so the config UI always has rows.
        foreach (RiskCalculator::signals() as $signal) {
            FraudMetricSetting::query()->firstOrCreate(
                ['key' => $signal->key()],
                ['enabled' => true, 'weight' => $signal->defaultWeight()]
            );
        }

        foreach (FraudLevelPolicy::LEVELS as $level) {
            FraudLevelPolicy::forLevel($level);
        }
    }

    public function toggleMetric(string $key): void
    {
        $setting = FraudMetricSetting::query()->where('key', $key)->firstOrFail();
        $setting->update(['enabled' => ! $setting->enabled]);
    }

    public function updateMetricWeight(string $key, $weight): void
    {
        FraudMetricSetting::query()->where('key', $key)->firstOrFail()
            ->update(['weight' => max(-100, min(100, (int) $weight))]);
    }

    public function updatePolicy(string $level, string $field, string $value): void
    {
        abort_unless(in_array($level, FraudLevelPolicy::LEVELS, true), 400);

        $policy = FraudLevelPolicy::forLevel($level);

        match ($field) {
            'user_status' => in_array($value, FraudLevelPolicy::USER_STATUSES, true)
                ? $policy->update(['user_status' => $value]) : null,
            'block_fingerprint' => $policy->update(['block_fingerprint' => $value === '1']),
            'block_ip' => $policy->update(['block_ip' => $value === '1']),
            'notify_admin' => $policy->update(['notify_admin' => $value === '1']),
            default => null,
        };
    }

    public function showUser(int $userId): void
    {
        $this->selectedUserId = $userId;
    }

    public function closeUser(): void
    {
        $this->selectedUserId = null;
    }

    public function setUserStatus(int $userId, string $status): void
    {
        abort_unless(in_array($status, FraudLevelPolicy::USER_STATUSES, true), 400);

        User::query()->findOrFail($userId)->forceFill(['fraud_status' => $status])->save();
    }

    public function toggleUserDevices(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $fingerprints = $user->devices()->pluck('fingerprint');
        $anyBlocked = $fingerprints->contains(fn (string $fp) => DeviceBlocklist::isBlocked($fp));

        foreach ($fingerprints as $fingerprint) {
            $anyBlocked
                ? DeviceBlocklist::unblock($fingerprint)
                : DeviceBlocklist::block($fingerprint, "Manually blocked from Fraud Intelligence (user #{$userId})", 'manual', auth()->id());
        }
    }

    public function toggleUserIps(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $ips = $user->ips()->pluck('ip_address');
        $anyBlocked = $ips->contains(fn (string $ip) => IpBlocklist::isBlocked($ip));

        foreach ($ips as $ip) {
            $anyBlocked
                ? IpBlocklist::unblock($ip)
                : IpBlocklist::block($ip, "Manually blocked from Fraud Intelligence (user #{$userId})", 'manual', auth()->id());
        }
    }

    public function markFalsePositive(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        $user->forceFill(['fraud_status' => 'active'])->save();

        UserRisk::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['score' => 0, 'level' => 'low', 'breakdown' => [], 'calculated_at' => now()]
        );

        foreach ($user->devices()->pluck('fingerprint') as $fingerprint) {
            DeviceBlocklist::unblock($fingerprint, 'fraud_engine');
        }

        foreach ($user->ips()->pluck('ip_address') as $ip) {
            IpBlocklist::unblock($ip, 'fraud_engine');
        }

        FraudAlert::query()->where('user_id', $user->id)->whereNull('reviewed_at')
            ->update(['reviewed_at' => now()]);

        $this->closeUser();
    }

    public function markAlertsReviewed(): void
    {
        FraudAlert::query()->whereNull('reviewed_at')->update(['reviewed_at' => now()]);
    }

    public function render(): mixed
    {
        $selectedUser = $this->selectedUserId
            ? User::query()->with(['risk', 'devices', 'ips'])->find($this->selectedUserId)
            : null;

        return view('livewire.admin.fraud-intelligence', [
            'highRiskCount' => UserRisk::query()->where('score', '>=', 60)->count(),
            'suspiciousDevices' => Device::query()->has('users', '>', 1)->count(),
            'linkedAccounts' => (int) floor(EntityLink::query()->count() / 2),
            'reviewCount' => User::query()->where('fraud_status', 'review')->count(),
            'unreviewedAlerts' => FraudAlert::query()->whereNull('reviewed_at')->count(),
            'distribution' => [
                'critical' => UserRisk::query()->where('level', 'critical')->count(),
                'high' => UserRisk::query()->where('level', 'high')->count(),
                'medium' => UserRisk::query()->where('level', 'medium')->count(),
                'low' => User::query()->count() - UserRisk::query()->where('level', '!=', 'low')->count(),
            ],
            'highRiskUsers' => UserRisk::query()->with('user')->where('score', '>', 0)->orderByDesc('score')->limit(10)->get(),
            'connections' => EntityLink::query()->with(['user', 'linkedUser'])->orderByDesc('strength')->limit(10)->get(),
            'metrics' => collect(RiskCalculator::signals())->map(function ($signal) {
                $setting = FraudMetricSetting::query()->where('key', $signal->key())->first();

                return [
                    'key' => $signal->key(),
                    'label' => $signal->label(),
                    'enabled' => $setting->enabled ?? true,
                    'weight' => $setting->weight ?? $signal->defaultWeight(),
                    'available' => $signal->available(),
                ];
            }),
            'policies' => collect(FraudLevelPolicy::LEVELS)->mapWithKeys(
                fn (string $level) => [$level => FraudLevelPolicy::forLevel($level)]
            ),
            'selectedUser' => $selectedUser,
            'selectedUserLinks' => $selectedUser
                ? EntityLink::query()->with('linkedUser')->where('user_id', $selectedUser->id)->orderByDesc('strength')->get()
                : collect(),
            'selectedUserThreats' => $selectedUser
                ? ThreatEvent::query()->where('user_id', $selectedUser->id)
                    ->orWhereIn('fingerprint', $selectedUser->devices->pluck('fingerprint'))->count()
                : 0,
            'selectedUserDevicesBlocked' => $selectedUser
                ? $selectedUser->devices->contains(fn ($device) => DeviceBlocklist::isBlocked($device->fingerprint))
                : false,
            'selectedUserIpsBlocked' => $selectedUser
                ? $selectedUser->ips->contains(fn ($ip) => IpBlocklist::isBlocked($ip->ip_address))
                : false,
        ]);
    }
}
