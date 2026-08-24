<?php

declare(strict_types=1);

namespace App\Security\Blocklist;

use App\Security\Models\SecurityBlock;

class DeviceBlocklist
{
    public static function isBlocked(?string $fingerprint): bool
    {
        return $fingerprint !== null
            && in_array($fingerprint, SecurityBlock::blockedValues(SecurityBlock::TYPE_DEVICE), true);
    }

    public static function block(string $fingerprint, string $reason, string $source = 'manual', ?int $createdBy = null): void
    {
        SecurityBlock::query()->firstOrCreate(
            ['type' => SecurityBlock::TYPE_DEVICE, 'value' => $fingerprint],
            ['reason' => $reason, 'source' => $source, 'created_by' => $createdBy]
        );
    }

    public static function unblock(string $fingerprint, ?string $source = null): void
    {
        SecurityBlock::query()
            ->where('type', SecurityBlock::TYPE_DEVICE)
            ->where('value', $fingerprint)
            ->when($source, fn ($query) => $query->where('source', $source))
            ->get()
            ->each->delete();
    }
}
