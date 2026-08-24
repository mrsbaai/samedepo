<?php

declare(strict_types=1);

namespace App\Fraud;

use App\Fraud\Models\EntityLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finds accounts connected to a user through shared devices and shared IPs,
 * and stores the connections (both directions) as entity links with a
 * 0-100 strength.
 */
class LinkDetector
{
    private const DEVICE_STRENGTH = 70;

    private const IP_STRENGTH = 25;

    public function sync(User $user): void
    {
        $deviceIds = $user->devices()->pluck('devices.id');
        $ips = $user->ips()->pluck('ip_address');

        $viaDevice = $deviceIds->isEmpty() ? collect() : DB::table('device_user')
            ->whereIn('device_id', $deviceIds)
            ->where('user_id', '!=', $user->id)
            ->distinct()
            ->pluck('user_id');

        $viaIp = $ips->isEmpty() ? collect() : DB::table('user_ips')
            ->whereIn('ip_address', $ips)
            ->where('user_id', '!=', $user->id)
            ->distinct()
            ->pluck('user_id');

        foreach ($viaDevice->merge($viaIp)->unique() as $linkedId) {
            $reasons = [];
            $strength = 0;

            if ($viaDevice->contains($linkedId)) {
                $reasons[] = 'shared device';
                $strength += self::DEVICE_STRENGTH;
            }

            if ($viaIp->contains($linkedId)) {
                $reasons[] = 'shared IP';
                $strength += self::IP_STRENGTH;
            }

            $strength = min(99, $strength);

            foreach ([[$user->id, $linkedId], [$linkedId, $user->id]] as [$a, $b]) {
                EntityLink::query()->updateOrCreate(
                    ['user_id' => $a, 'linked_user_id' => $b],
                    ['strength' => $strength, 'reasons' => $reasons]
                );
            }
        }
    }
}
