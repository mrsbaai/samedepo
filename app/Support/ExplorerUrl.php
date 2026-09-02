<?php

declare(strict_types=1);

namespace App\Support;

final class ExplorerUrl
{
    public static function for(string $type, string $network, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($network) {
            'bitcoin' => $type === 'address'
                ? 'https://mempool.space/address/'.$value
                : 'https://mempool.space/tx/'.$value,
            'usdt_trc20' => $type === 'address'
                ? 'https://tronscan.org/#/address/'.$value
                : 'https://tronscan.org/#/transaction/'.$value,
            'usdt_erc20' => $type === 'address'
                ? 'https://etherscan.io/address/'.$value
                : 'https://etherscan.io/tx/'.$value,
            default => null,
        };
    }
}
