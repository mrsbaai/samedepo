<?php

declare(strict_types=1);

namespace App\Fraud;

use App\Fraud\Models\FraudAlert;
use App\Fraud\Models\FraudLevelPolicy;
use App\Models\User;
use App\Notifications\FraudLevelAlert;
use App\Security\Blocklist\DeviceBlocklist;
use App\Security\Blocklist\IpBlocklist;
use Illuminate\Support\Facades\Notification;

/**
 * Applies the configured fraud-level policy to a user: user status,
 * fingerprint block, IP block, and admin notification. Blocks created here
 * use the fraud_engine source and are lifted automatically when the level
 * drops; manual and threat_detector blocks are never touched.
 */
class FraudPolicy
{
    public function apply(User $user, string $level, int $score): void
    {
        $policy = FraudLevelPolicy::forLevel($level);

        $user->forceFill(['fraud_status' => $policy->user_status])->save();

        $fingerprints = $user->devices()->pluck('fingerprint');
        $ips = $user->ips()->pluck('ip_address');

        foreach ($fingerprints as $fingerprint) {
            $policy->block_fingerprint
                ? DeviceBlocklist::block($fingerprint, "Fraud level {$level} (score {$score}) for user #{$user->id}", 'fraud_engine')
                : DeviceBlocklist::unblock($fingerprint, 'fraud_engine');
        }

        foreach ($ips as $ip) {
            $policy->block_ip
                ? IpBlocklist::block($ip, "Fraud level {$level} (score {$score}) for user #{$user->id}", 'fraud_engine')
                : IpBlocklist::unblock($ip, 'fraud_engine');
        }

        if ($policy->notify_admin) {
            $alert = FraudAlert::query()->create([
                'user_id' => $user->id,
                'level' => $level,
                'score' => $score,
            ]);

            $admins = User::query()->where('is_admin', true)->get();
            Notification::send($admins, new FraudLevelAlert($alert));
        }
    }
}
