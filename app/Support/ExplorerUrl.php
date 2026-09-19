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

        if (! Network::exists($network)) {
            return null;
        }

        $template = Network::explorerTx($network);
        $txUrl = str_replace('{hash}', $value, $template);

        if ($type === 'address') {
            // Address pages are derived from the tx template's host for the
            // known explorers; tokens share the chain's explorer.
            $host = parse_url($txUrl, PHP_URL_HOST) ?: '';

            return match (true) {
                str_contains($host, 'mempool.space') => 'https://mempool.space/address/'.$value,
                str_contains($host, 'litecoinspace.org') => 'https://litecoinspace.org/address/'.$value,
                str_contains($host, 'tronscan.org') => 'https://tronscan.org/#/address/'.$value,
                str_contains($host, 'etherscan.io') => 'https://etherscan.io/address/'.$value,
                str_contains($host, 'bscscan.com') => 'https://bscscan.com/address/'.$value,
                default => null,
            };
        }

        return $txUrl;
    }
}
