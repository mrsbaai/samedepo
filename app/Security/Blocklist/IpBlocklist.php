<?php

declare(strict_types=1);

namespace App\Security\Blocklist;

use App\Security\Models\SecurityBlock;

class IpBlocklist
{
    public static function isBlocked(?string $ip): bool
    {
        return $ip !== null && in_array($ip, SecurityBlock::blockedValues(SecurityBlock::TYPE_IP), true);
    }

    public static function block(string $ip, string $reason, string $source = 'manual', ?int $createdBy = null): void
    {
        SecurityBlock::query()->firstOrCreate(
            ['type' => SecurityBlock::TYPE_IP, 'value' => $ip],
            ['reason' => $reason, 'source' => $source, 'created_by' => $createdBy]
        );
    }

    public static function unblock(string $ip, ?string $source = null): void
    {
        SecurityBlock::query()
            ->where('type', SecurityBlock::TYPE_IP)
            ->where('value', $ip)
            ->when($source, fn ($query) => $query->where('source', $source))
            ->get()
            ->each->delete();
    }
}
