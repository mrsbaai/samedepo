<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use Nimiq\XPub;
use RuntimeException;
use StephenHill\Base58;

class AddressGenerator
{
    private const NETWORKS = ['bitcoin', 'usdt_trc20', 'usdt_erc20', 'usdt_base'];

    public function generate(string $network, int $index): string
    {
        if (! in_array($network, self::NETWORKS, true)) {
            throw new RuntimeException("Unsupported network: {$network}");
        }

        $xpub = config("blockchain.{$network}.xpub");

        if (empty($xpub)) {
            throw new RuntimeException("Missing extended public key configuration for network: {$network}");
        }

        $child = XPub::fromString($xpub)->derive([0, $index]);

        return match ($network) {
            'bitcoin' => $child->toAddress('btc'),
            'usdt_erc20' => $child->toAddress('eth'),
            'usdt_base' => $child->toAddress('eth'),
            'usdt_trc20' => $this->toTronAddress($child->toAddress('eth')),
            default => throw new RuntimeException("Unsupported network: {$network}"),
        };
    }

    public function networks(): array
    {
        return self::NETWORKS;
    }

    private function toTronAddress(string $ethAddress): string
    {
        $payload = hex2bin('41'.substr($ethAddress, 2));
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return (new Base58)->encode($payload.$checksum);
    }
}
